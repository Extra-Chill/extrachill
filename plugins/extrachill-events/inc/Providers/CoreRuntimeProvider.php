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

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/GenreSync.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/genre-sync.php';
		extrachill_events_init_genre_sync();
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/priority-boost-service-authority.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/ticket-service-authority.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyVerdict.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyVerdictsTable.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyVerdictResolver.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/PlatformDetector.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyFingerprinter.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueExpansionRunner.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/EventSourceRampEvaluator.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/RsvpPassesTable.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/GuestListPartiesTable.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/OwnerGuestListTable.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/ArtistCoverageSync.php';
		\ExtraChillEvents\Core\ArtistCoverageSync::register();
		self::load_operator_services();
	}

	/**
	 * Load the services behind the extrachill-cli operator commands.
	 *
	 * The `wp extrachill events|venues|artists` adapters live in
	 * extrachill-cli; the qualification, flow, location, and classification
	 * logic they call stays here. Loading declares classes only.
	 */
	private static function load_operator_services(): void {
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyCohortDeriver.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyStatsReport.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/FlowOps.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/FlowHelpers.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueRequalifier.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/FlowLocationGuard.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/FlowLocationRepairer.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/LocationIntegrityAuditor.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifiedRootLocation.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/RootLocationRepair.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/RootLocationReconciler.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/GenreProjectionBackfill.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueTierClassifier.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/ArtistGenreClassifier.php';
	}
}
