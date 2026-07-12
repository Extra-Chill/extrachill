<?php
/**
 * Plugin Name: Extra Chill Events
 * Plugin URI: https://extrachill.com
 * Description: Calendar integration with template overrides, data-machine-events badge/button styling, breadcrumb system, and related events for events.extrachill.com.
 * Version: 0.38.3
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * Requires Plugins: data-machine, data-machine-events
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-events
 * Requires at least: 6.9
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Network: false
 *
 * @package ExtraChillEvents
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_EVENTS_VERSION', '0.38.3' );
define( 'EXTRACHILL_EVENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_EVENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACHILL_EVENTS_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_EVENTS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI && file_exists( __DIR__ . '/inc/Cli/AddCityCommand.php' ) ) {
	require_once __DIR__ . '/inc/Cli/AddCityCommand.php';
	\WP_CLI::add_command( 'extrachill-events add-city', \ExtraChillEvents\Cli\AddCityCommand::class );

	// Qualify v2 — verdict-log subcommands hung off the existing
	// `wp extrachill venues` parent (registered by extrachill-cli).
	// Core classes need to load before the CLI commands instantiate them.
	require_once __DIR__ . '/inc/Core/QualifyVerdict.php';
	require_once __DIR__ . '/inc/Core/QualifyVerdictsTable.php';
	require_once __DIR__ . '/inc/Core/QualifyVerdictResolver.php';
	require_once __DIR__ . '/inc/Core/PlatformDetector.php';
	require_once __DIR__ . '/inc/Core/QualifyFingerprinter.php';

	require_once __DIR__ . '/inc/Cli/QualifyStatsCommand.php';
	\WP_CLI::add_command( 'extrachill venues qualify-stats', \ExtraChillEvents\Cli\QualifyStatsCommand::class );

	require_once __DIR__ . '/inc/Cli/RequalifyPendingCommand.php';
	\WP_CLI::add_command( 'extrachill venues requalify-pending', \ExtraChillEvents\Cli\RequalifyPendingCommand::class );

	require_once __DIR__ . '/inc/Cli/FlowOps.php';
	require_once __DIR__ . '/inc/Cli/FlowHelpers.php';

	require_once __DIR__ . '/inc/Cli/RequalifyFlowCommand.php';
	\WP_CLI::add_command( 'extrachill venues requalify-flow', \ExtraChillEvents\Cli\RequalifyFlowCommand::class );

	require_once __DIR__ . '/inc/Cli/UnqualifiableFlowsCommand.php';
	\WP_CLI::add_command( 'extrachill venues unqualifiable-flows', \ExtraChillEvents\Cli\UnqualifiableFlowsCommand::class );

	// Pipeline assignment audit (issue #99). Lives under
	// `wp extrachill events flows` so it's grouped with the other
	// events-domain operator tooling rather than the venues surface.
	require_once __DIR__ . '/inc/Cli/AuditPipelinesCommand.php';
	\WP_CLI::add_command( 'extrachill events flows audit-pipelines', \ExtraChillEvents\Cli\AuditPipelinesCommand::class );

	// Location / flow_config hygiene (extrachill-events#98).
	// Issue #98 — Both commands operate against the events subsite (blog 7).
	// Always invoke with `--url=events.extrachill.com` so $wpdb->prefix is c8c_7_.
	require_once __DIR__ . '/inc/Core/FlowLocationGuard.php';
	require_once __DIR__ . '/inc/Cli/RepairFlowLocationsCommand.php';
	\WP_CLI::add_command( 'extrachill events flows repair-locations', \ExtraChillEvents\Cli\RepairFlowLocationsCommand::class );

	require_once __DIR__ . '/inc/Cli/PruneOrphanLocationsCommand.php';
	\WP_CLI::add_command( 'extrachill events locations prune-orphans', \ExtraChillEvents\Cli\PruneOrphanLocationsCommand::class );

	require_once __DIR__ . '/inc/Cli/BackfillVenueMetaCommand.php';
	\WP_CLI::add_command( 'extrachill events venues backfill-meta', \ExtraChillEvents\Cli\BackfillVenueMetaCommand::class );

	// Honest authorship backfill (issue #207 Phase 3). Reattributes historical
	// automation authored under a human (uid 1) onto the network bot account.
	// Dry-run by default; --apply (or --commit) to mutate. Operates across
	// blogs 7 (data_machine_events) and 11 (festival_wire); never touches blog 1.
	require_once __DIR__ . '/inc/Cli/BackfillAuthorshipCommand.php';
	\WP_CLI::add_command( 'extrachill events backfill-authorship', \ExtraChillEvents\Cli\BackfillAuthorshipCommand::class );
}

// Recheck handler must be loaded outside the WP_CLI guard so the Action
// Scheduler hook fires whether the action runs via web request, cron, or
// CLI runner.
require_once __DIR__ . '/inc/Core/QualifyVerdict.php';
require_once __DIR__ . '/inc/Core/QualifyVerdictsTable.php';
require_once __DIR__ . '/inc/Cli/FlowOps.php';
require_once __DIR__ . '/inc/Core/QualifyRecheckHandler.php';
\ExtraChillEvents\Core\QualifyRecheckHandler::register();

require_once __DIR__ . '/inc/admin/network-settings.php';
\ExtraChillEvents\Admin\NetworkSettings::register();

/**
 * Register the weekly qualify digest task with Data Machine's system-task
 * surface. Loaded on plugins_loaded so the DM SystemTask base class is
 * available; gated on its existence so this plugin remains compatible
 * with installs that don't have data-machine activated.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( '\\DataMachine\\Engine\\AI\\System\\Tasks\\SystemTask' ) ) {
			return;
		}
		require_once __DIR__ . '/inc/Steps/QualifyDigest/QualifyDigestSystemTask.php';

		add_filter(
			'datamachine_tasks',
			function ( array $tasks ): array {
				$tasks[ \ExtraChillEvents\Steps\QualifyDigest\QualifyDigestSystemTask::TASK_TYPE ] = \ExtraChillEvents\Steps\QualifyDigest\QualifyDigestSystemTask::class;
				return $tasks;
			}
		);

		add_filter(
			'datamachine_recurring_schedules',
			function ( array $schedules ): array {
				/**
				 * Filter the qualify digest schedule definition. Allows
				 * operators to flip interval, change the first-run anchor,
				 * etc. without forking the plugin.
				 */
				$schedules['extrachill_qualify_digest'] = apply_filters(
					'dme_qualify_digest_schedule',
					array(
						'task_type'          => \ExtraChillEvents\Steps\QualifyDigest\QualifyDigestSystemTask::TASK_TYPE,
						'interval'           => 'weekly',
						'enabled_setting'    => 'dme_qualify_digest_enabled',
						'default_enabled'    => true,
						'label'              => 'Weekly — Mondays 09:00 UTC',
						'first_run_callback' => 'strtotime',
						'first_run_arg'      => 'next monday 09:00 UTC',
						'task_params'        => array(
							'since'   => '1 week ago',
							'format'  => 'html',
							'dry_run' => false,
						),
					)
				);
				return $schedules;
			}
		);
	},
	20
);

/**
 * ExtraChillEvents
 *
 * Singleton class managing data-machine-events integration with homepage/archive template
 * overrides, badge/button styling, breadcrumb system, and SEO redirects for
 * events.extrachill.com (blog ID 7).
 *
 * @since 0.1.0
 */
class ExtraChillEvents {

	private static $instance = null;
	private $integrations    = array();

	/**
	 * Get singleton instance
	 *
	 * @return ExtraChillEvents
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
		$this->load_dependencies();
		$this->init_integrations();
	}

	private function init_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init_data_machine_handlers' ), 20 );
		add_action( 'init', array( $this, 'init_abilities' ), 25 );
		add_action( 'plugins_loaded', array( $this, 'maybe_install_schema' ), 20 );

		// Artist URL Import moderation queue admin screen (migrated from
		// data-machine-events in #200). Hooks DME's public post-type menu
		// filter, so it must instantiate before that filter fires.
		if ( is_admin() ) {
			add_action( 'init', array( $this, 'init_artist_url_admin' ), 5 );
		}
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			'extrachill-events',
			false,
			dirname( EXTRACHILL_EVENTS_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Load plugin dependencies via direct includes
	 *
	 * Composer autoloader exists for development dependencies only.
	 * All plugin code uses direct require_once includes.
	 */
	private function load_dependencies() {
		$autoload_file = EXTRACHILL_EVENTS_PLUGIN_DIR . 'vendor/autoload.php';
		if ( file_exists( $autoload_file ) ) {
			require_once $autoload_file;
		}

		// Qualify v2 — verdict taxonomy + persistent verdict log + resolver.
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyVerdict.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyVerdictsTable.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyVerdictResolver.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/PlatformDetector.php';

		// Artist URL Import subsystem (migrated from data-machine-events in #200).
		// Moderation-queue table + REST controller/routes. The abilities load in
		// init_abilities(); the admin screen instantiates in init_admin().
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/ArtistUrlSubmissionsTable.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Api/Controllers/ArtistUrlImport.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Api/ArtistUrlImportRoutes.php';

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/data-machine-events/init.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/cache-groups.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/nav.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/priority-venue-ordering.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/priority-event-ordering.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/location-meta.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/archive-map.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/location-map.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/venue-map.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/artist-map.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/location-seo.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/account-market.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/near-me.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/discovery-pages.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/router-pages.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/location-normalizer.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/calendar-stats.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/admin/priority-venues.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/admin/priority-events.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/single-event/breadcrumbs.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/single-event/related-events.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/single-event/network-bridge.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/single-event/share-button.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/home/actions.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/concert-tracking-integration.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/my-shows.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/my-shows-scope-token.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/my-shows-calendar-filter.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/my-shows-map-filter.php';
	}

	public function init_data_machine_handlers() {
		if ( ! class_exists( 'DataMachine\Core\Steps\Fetch\Handlers\FetchHandler' ) ) {
			return;
		}

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/handlers/event-roundup/EventRoundupSettings.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/handlers/event-roundup/EventRoundupHandler.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/handlers/event-roundup/RoundupPublishSettings.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/handlers/event-roundup/RoundupPublishHandler.php';

		new \ExtraChillEvents\Handlers\EventRoundup\EventRoundupHandler();
		new \ExtraChillEvents\Handlers\EventRoundup\RoundupPublishHandler();

		// Register the event roundup template with Data Machine's image
		// template registry. The actual GD work lives in the template class;
		// the handler/abilities just call datamachine/render-image-template.
		if ( file_exists( EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Templates/EventRoundupTemplate.php' ) ) {
			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Templates/EventRoundupTemplate.php';
			add_filter(
				'datamachine/image_generation/templates',
				function ( array $templates ): array {
					$templates['event_roundup'] = \ExtraChillEvents\Templates\EventRoundupTemplate::class;
					return $templates;
				}
			);
		}
	}

	public function init_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// Events-domain abilities (procedural, per issue #68).
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/abilities/register.php';

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventRoundupAbilities.php';
		new \ExtraChillEvents\Abilities\EventRoundupAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventLocationAlignmentAbilities.php';
		new \ExtraChillEvents\Abilities\EventLocationAlignmentAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/LocationEventAbilities.php';
		new \ExtraChillEvents\Abilities\LocationEventAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/PriorityVenueAbilities.php';
		new \ExtraChillEvents\Abilities\PriorityVenueAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/PriorityEventAbilities.php';
		new \ExtraChillEvents\Abilities\PriorityEventAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/CityAbilities.php';
		new \ExtraChillEvents\Abilities\CityAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueDiscoveryAbilities.php';
		new \ExtraChillEvents\Abilities\VenueDiscoveryAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueQualificationAbilities.php';
		new \ExtraChillEvents\Abilities\VenueQualificationAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueAddAbilities.php';
		new \ExtraChillEvents\Abilities\VenueAddAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventSubmissionAbilities.php';
		new \ExtraChillEvents\Abilities\EventSubmissionAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/MarketReportAbilities.php';
		new \ExtraChillEvents\Abilities\MarketReportAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventTimeAuditAbilities.php';
		new \ExtraChillEvents\Abilities\EventTimeAuditAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/QualifyDigestAbilities.php';
		new \ExtraChillEvents\Abilities\QualifyDigestAbilities();

		// Artist URL Import abilities (migrated from data-machine-events in #200).
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/ArtistUrlImportAbilities.php';
		new \ExtraChillEvents\Abilities\ArtistUrlImportAbilities();
	}

	/**
	 * Initialize event plugin integrations
	 *
	 * Conditionally initializes data-machine-events integration if plugin is active.
	 *
	 * Detection uses the DATA_MACHINE_EVENTS_POST_TYPE constant from
	 * data-machine-events' public integration API (inc/public-api.php) so the
	 * check survives internal namespace changes in DM-events.
	 */
	private function init_integrations() {
		if ( defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ) {
			extrachill_events_init_data_machine_integration();
		}
	}

	public function activate() {
		// Create/upgrade the qualify verdicts table at activation. Safe to
		// call repeatedly — dbDelta handles idempotency.
		\ExtraChillEvents\Core\QualifyVerdictsTable::create_table();

		// Artist URL submissions table (migrated from data-machine-events in
		// #200). Same table name as before — ownership transfers, no data move.
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/ArtistUrlSubmissionsTable.php';
		\ExtraChillEvents\Core\ArtistUrlSubmissionsTable::create_table();

		flush_rewrite_rules();
	}

	public function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Idempotent schema installer for the qualify verdicts table.
	 *
	 * Runs on plugins_loaded so the table is available even when the plugin
	 * was already active when the new schema shipped (i.e. without a fresh
	 * activation). Cheap when up to date — short-circuits on a stored option.
	 */
	public function maybe_install_schema() {
		if ( class_exists( '\\ExtraChillEvents\\Core\\QualifyVerdictsTable' ) ) {
			\ExtraChillEvents\Core\QualifyVerdictsTable::maybe_install();
		}

		// Artist URL submissions moderation-queue table (migrated from
		// data-machine-events in #200). Network-scoped; idempotent install.
		if ( class_exists( '\\ExtraChillEvents\\Core\\ArtistUrlSubmissionsTable' ) ) {
			\ExtraChillEvents\Core\ArtistUrlSubmissionsTable::maybe_install();
		}
	}

	/**
	 * Instantiate the Artist URL Import moderation-queue admin screen.
	 *
	 * Hooks DME's public `data_machine_events_post_type_menu_items` filter to
	 * add a submenu under the Events post-type menu. Runs at init priority 5
	 * so the filter is registered before DME builds its menu.
	 */
	public function init_artist_url_admin() {
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/admin/ArtistUrlSubmissionsAdmin.php';
		new \ExtraChillEvents\Admin\ArtistUrlSubmissionsAdmin();
	}

	public function get_integrations() {
		return $this->integrations;
	}
}

// Top-level functions + hook wiring live in the procedural bootstrap so this
// file holds only the ExtraChillEvents class (WPCS Universal.Files.SeparateFunctionsFromOO).
require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/bootstrap.php';
