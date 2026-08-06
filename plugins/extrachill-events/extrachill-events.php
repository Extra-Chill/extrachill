<?php
/**
 * Plugin Name: Extra Chill Events
 * Plugin URI: https://extrachill.com
 * Description: Calendar integration with template overrides, data-machine-events badge/button styling, breadcrumb system, and related events for events.extrachill.com.
 * Version: 0.59.0
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

define( 'EXTRACHILL_EVENTS_VERSION', '0.59.0' );
define( 'EXTRACHILL_EVENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_EVENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACHILL_EVENTS_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_EVENTS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once __DIR__ . '/inc/Providers/CliProvider.php';
require_once __DIR__ . '/inc/Providers/IngestionProvider.php';
require_once __DIR__ . '/inc/Providers/PublicExperienceProvider.php';
require_once __DIR__ . '/inc/Providers/DataMachineEventsProvider.php';

\ExtraChillEvents\Providers\CliProvider::register();
\ExtraChillEvents\Providers\IngestionProvider::register();

require_once __DIR__ . '/inc/admin/network-settings.php';
\ExtraChillEvents\Admin\NetworkSettings::register();

require_once __DIR__ . '/inc/core/datamachine-settings.php';

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

	/** @var self|null */
	private static $instance = null;

	/** @var array */
	private $integrations = array();

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
		add_filter( 'ec_feature_ceilings', array( $this, 'register_feature_ceilings' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'init_abilities' ), 25 );
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

	/** Keep venue booking tools on the established internal-team rollout rung. */
	public function register_feature_ceilings( array $ceilings ): array {
		$ceilings[ \ExtraChillEvents\Core\VenueAuthorization::FEATURE ] = 'team';
		return $ceilings;
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
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyFingerprinter.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueExpansionRunner.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/EventSourceRampEvaluator.php';

		// Artist URL Import subsystem (migrated from data-machine-events in #200).
		// Moderation-queue table + REST controller/routes. The abilities load in
		// init_abilities(); the admin screen instantiates in init_admin().
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/ArtistUrlSubmissionsTable.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingSchema.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/LocalSupportSchema.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VendorRequestSchema.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueMembershipRepository.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueAuthorization.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/LocalSupportAuthorization.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VendorRequestAuthorization.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueMembershipService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueInvitationToken.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueOnboardingRepository.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueOnboardingService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueInvitationDeliveryWorker.php';
		\ExtraChillEvents\Core\VenueInvitationDeliveryWorker::register();
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingRepository.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/LocalSupportRepository.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/LocalSupportService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/LocalSupportWorkspace.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VendorRequestRepository.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VendorRequestService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VendorRequestNotificationService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingActivityRepository.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingNotificationService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/LocalSupportNotificationAdapter.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/LocalSupportNotificationService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingCommunicationService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingCorrespondenceAutomationService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingHoldRepository.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingMutationService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingEventSyncService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingEventConversionService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingMarketingService.php';
		\ExtraChillEvents\Core\BookingMarketingService::register();
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingLifecycle.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingPrivateFileProvider.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/LocalBookingPrivateFileProvider.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingPrivateFileProviders.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingAttachmentPolicy.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingAttachmentRepository.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingAttachmentDeliveryRepository.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingAttachmentService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingInquiryAdmissionService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueBookingConfig.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueBookingEmbed.php';
		\ExtraChillEvents\Core\VenueBookingEmbed::register();
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/TicketReconciliationService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/TicketSettlementService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/ShowSettlementService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingReportingService.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/BookingPrivacyService.php';
		\ExtraChillEvents\Core\BookingPrivacyService::register();
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueProfile.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/CanonicalEventPublicationGuard.php';
		\ExtraChillEvents\Core\BookingHoldRepository::register();
		\ExtraChillEvents\Core\BookingCommunicationService::register();
		\ExtraChillEvents\Core\BookingCorrespondenceAutomationService::register();
		\ExtraChillEvents\Core\BookingNotificationService::register();
		\ExtraChillEvents\Core\LocalSupportNotificationService::register();
		\ExtraChillEvents\Core\VendorRequestNotificationService::register();
		new \ExtraChillEvents\Core\CanonicalEventPublicationGuard();
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Api/Controllers/ArtistUrlImport.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Api/ArtistUrlImportRoutes.php';

		\ExtraChillEvents\Providers\PublicExperienceProvider::register();
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

		if ( \ExtraChillEvents\Core\BookingSchema::is_ready() ) {
			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueMembershipAbilities.php';
			new \ExtraChillEvents\Abilities\VenueMembershipAbilities();

			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueOnboardingAbilities.php';
			new \ExtraChillEvents\Abilities\VenueOnboardingAbilities();

			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueBookingConfigAbilities.php';
			new \ExtraChillEvents\Abilities\VenueBookingConfigAbilities();

			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueProfileAbilities.php';
			new \ExtraChillEvents\Abilities\VenueProfileAbilities();

			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/ManagedVenueVoicesAbilities.php';
			new \ExtraChillEvents\Abilities\ManagedVenueVoicesAbilities();

			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueBookingAbilities.php';
			new \ExtraChillEvents\Abilities\VenueBookingAbilities();

			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/BookingAttachmentAbilities.php';
			new \ExtraChillEvents\Abilities\BookingAttachmentAbilities();

			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueBookingHoldAbilities.php';
			new \ExtraChillEvents\Abilities\VenueBookingHoldAbilities();

			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueBookingMutationAbilities.php';
			new \ExtraChillEvents\Abilities\VenueBookingMutationAbilities();

			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueBookingEventAbilities.php';
			new \ExtraChillEvents\Abilities\VenueBookingEventAbilities();

			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueBookingCommunicationAbilities.php';
			new \ExtraChillEvents\Abilities\VenueBookingCommunicationAbilities();

			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueBookingMarketingAbilities.php';
			new \ExtraChillEvents\Abilities\VenueBookingMarketingAbilities();

			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/TicketSettlementAbilities.php';
			new \ExtraChillEvents\Abilities\TicketSettlementAbilities();

			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/ShowSettlementAbilities.php';
			new \ExtraChillEvents\Abilities\ShowSettlementAbilities();

			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/BookingReportingAbilities.php';
			new \ExtraChillEvents\Abilities\BookingReportingAbilities();

			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/BookingPrivacyAbilities.php';
			new \ExtraChillEvents\Abilities\BookingPrivacyAbilities();
		}

		if ( \ExtraChillEvents\Core\LocalSupportSchema::is_ready() ) {
			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/LocalSupportAbilities.php';
			new \ExtraChillEvents\Abilities\LocalSupportAbilities();
		}

		if ( \ExtraChillEvents\Core\VendorRequestSchema::is_ready() ) {
			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VendorRequestAbilities.php';
			new \ExtraChillEvents\Abilities\VendorRequestAbilities();
		}

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/PriorityVenueAbilities.php';
		new \ExtraChillEvents\Abilities\PriorityVenueAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/PriorityEventAbilities.php';
		new \ExtraChillEvents\Abilities\PriorityEventAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/FlowLocationGuard.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/CityAbilities.php';
		new \ExtraChillEvents\Abilities\CityAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueDiscoveryAbilities.php';
		new \ExtraChillEvents\Abilities\VenueDiscoveryAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueQualificationAbilities.php';
		new \ExtraChillEvents\Abilities\VenueQualificationAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueAddAbilities.php';
		new \ExtraChillEvents\Abilities\VenueAddAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueExpansionAbilities.php';
		new \ExtraChillEvents\Abilities\VenueExpansionAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventSubmissionAbilities.php';
		new \ExtraChillEvents\Abilities\EventSubmissionAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/MarketReportAbilities.php';
		new \ExtraChillEvents\Abilities\MarketReportAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/EventSourceRampAbilities.php';
		new \ExtraChillEvents\Abilities\EventSourceRampAbilities();

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
		\ExtraChillEvents\Providers\DataMachineEventsProvider::register();
	}

	public function activate() {
		// Create/upgrade the qualify verdicts table at activation. Safe to
		// call repeatedly — dbDelta handles idempotency.
		\ExtraChillEvents\Core\QualifyVerdictsTable::create_table();

		// Artist URL submissions table (migrated from data-machine-events in
		// #200). Same table name as before — ownership transfers, no data move.
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/ArtistUrlSubmissionsTable.php';
		\ExtraChillEvents\Core\ArtistUrlSubmissionsTable::create_table();

		// Private, site-scoped venue booking tables.
		\ExtraChillEvents\Core\BookingSchema::install();
		\ExtraChillEvents\Core\LocalSupportSchema::install();
		\ExtraChillEvents\Core\VendorRequestSchema::install();

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

		if ( class_exists( '\\ExtraChillEvents\\Core\\BookingSchema' ) ) {
			\ExtraChillEvents\Core\BookingSchema::maybe_install();
		}

		if ( class_exists( '\\ExtraChillEvents\\Core\\LocalSupportSchema' ) ) {
			\ExtraChillEvents\Core\LocalSupportSchema::maybe_install();
		}

		if ( class_exists( '\\ExtraChillEvents\\Core\\VendorRequestSchema' ) ) {
			\ExtraChillEvents\Core\VendorRequestSchema::maybe_install();
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
