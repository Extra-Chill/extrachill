<?php
/**
 * Venue and booking dependency registration.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Loads the ordered venue and booking feature group once. */
final class VenueBookingProvider {

	/**
	 * Whether dependencies have loaded.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register venue and booking schemas, services, and hooks.
	 *
	 * Optional integrations are guarded by their owning services and are not
	 * prerequisites for loading the domain.
	 *
	 * @return bool Whether the provider is registered.
	 */
	public static function register(): bool {
		if ( self::$registered ) {
			return true;
		}

		if ( ! function_exists( 'add_action' ) || ! function_exists( 'add_filter' ) ) {
			return false;
		}

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

		self::$registered = true;
		return true;
	}
}
