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
		if ( self::$registered || ! defined( 'WP_CLI' ) || ! WP_CLI || ! file_exists( EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/AddCityCommand.php' ) ) { // @phpstan-ignore booleanAnd.rightAlwaysTrue
			return;
		}

		self::$registered = true;

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/EventSourceRampEvaluator.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/EventSourceRampCommand.php';
		\WP_CLI::add_command( 'extrachill events ramp', \ExtraChillEvents\Cli\EventSourceRampCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/AddCityCommand.php';
		\WP_CLI::add_command( 'extrachill-events add-city', \ExtraChillEvents\Cli\AddCityCommand::class );
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/ExpandVenuesCommand.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/VenueExpansionReportCommand.php';
		\WP_CLI::add_command( 'extrachill-events expand', \ExtraChillEvents\Cli\ExpandVenuesCommand::class );
		\WP_CLI::add_command( 'extrachill-events expand-report', \ExtraChillEvents\Cli\VenueExpansionReportCommand::class );

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

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/FlowOps.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/FlowHelpers.php';

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/RequalifyFlowCommand.php';
		\WP_CLI::add_command( 'extrachill venues requalify-flow', \ExtraChillEvents\Cli\RequalifyFlowCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/UnqualifiableFlowsCommand.php';
		\WP_CLI::add_command( 'extrachill venues unqualifiable-flows', \ExtraChillEvents\Cli\UnqualifiableFlowsCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/AuditPipelinesCommand.php';
		\WP_CLI::add_command( 'extrachill events flows audit-pipelines', \ExtraChillEvents\Cli\AuditPipelinesCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/FlowLocationGuard.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/RepairFlowLocationsCommand.php';
		\WP_CLI::add_command( 'extrachill events flows repair-locations', \ExtraChillEvents\Cli\RepairFlowLocationsCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/PruneOrphanLocationsCommand.php';
		\WP_CLI::add_command( 'extrachill events locations prune-orphans', \ExtraChillEvents\Cli\PruneOrphanLocationsCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/LocationIntegrityAuditor.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/AuditLocationIntegrityCommand.php';
		\WP_CLI::add_command( 'extrachill events locations audit-integrity', \ExtraChillEvents\Cli\AuditLocationIntegrityCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/BackfillVenueMetaCommand.php';
		\WP_CLI::add_command( 'extrachill events venues backfill-meta', \ExtraChillEvents\Cli\BackfillVenueMetaCommand::class );

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Cli/BackfillAuthorshipCommand.php';
		\WP_CLI::add_command( 'extrachill events backfill-authorship', \ExtraChillEvents\Cli\BackfillAuthorshipCommand::class );
	}
}
