<?php
/**
 * Events operator CLI registration.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Loads and registers Events-owned WP-CLI commands. */
final class CliProvider {

	/**
	 * Whether command registration has run.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/** Register commands when the WP-CLI runtime is available. */
	public static function register(): void {
		if ( self::$registered || ! defined( 'WP_CLI' ) ) {
			return;
		}

		self::$registered = true;

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/EventSourceRampEvaluator.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/EventSourceRampCommand.php';
		\WP_CLI::add_command( 'extrachill events ramp', \ExtraChillEvents\Cli\EventSourceRampCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyVerdict.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyVerdictsTable.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyCohortDeriver.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyVerdictResolver.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/PlatformDetector.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyFingerprinter.php';

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/QualifyStatsCommand.php';
		\WP_CLI::add_command( 'extrachill venues qualify-stats', \ExtraChillEvents\Cli\QualifyStatsCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/RequalifyPendingCommand.php';
		\WP_CLI::add_command( 'extrachill venues requalify-pending', \ExtraChillEvents\Cli\RequalifyPendingCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/FlowOps.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/FlowHelpers.php';

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/RequalifyFlowCommand.php';
		\WP_CLI::add_command( 'extrachill venues requalify-flow', \ExtraChillEvents\Cli\RequalifyFlowCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/UnqualifiableFlowsCommand.php';
		\WP_CLI::add_command( 'extrachill venues unqualifiable-flows', \ExtraChillEvents\Cli\UnqualifiableFlowsCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/FlowLocationGuard.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/RepairFlowLocationsCommand.php';
		\WP_CLI::add_command( 'extrachill events flows repair-locations', \ExtraChillEvents\Cli\RepairFlowLocationsCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/LocationIntegrityAuditor.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/AuditLocationIntegrityCommand.php';
		\WP_CLI::add_command( 'extrachill events locations audit-integrity', \ExtraChillEvents\Cli\AuditLocationIntegrityCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifiedRootLocation.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/RootLocationRepair.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/ReconcileRootLocationsCommand.php';
		\WP_CLI::add_command( 'extrachill events locations reconcile-roots', \ExtraChillEvents\Cli\ReconcileRootLocationsCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/SyncGenresCommand.php';
		\WP_CLI::add_command( 'extrachill events sync-genres', \ExtraChillEvents\Cli\SyncGenresCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/ClassifyVenueTierCommand.php';
		\WP_CLI::add_command( 'extrachill venues classify-tier', \ExtraChillEvents\Cli\ClassifyVenueTierCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/ClassifyGenreCommand.php';
		\WP_CLI::add_command( 'extrachill artists classify-genre', \ExtraChillEvents\Cli\ClassifyGenreCommand::class );
	}
}
