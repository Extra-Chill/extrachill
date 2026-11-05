<?php
/**
 * Booking-owned dependency registration.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Single entry point for booking, depending only on venue-owned services. */
final class BookingProvider {

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

		// Booking builds on the venue layer; it never loads venue code itself.
		if ( ! VenueProvider::register() ) {
			return false;
		}

		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingSchema.php';
		add_action( 'extrachill_events_after_venue_schema_install', array( self::class, 'install_schema' ) );
		add_filter( 'extrachill_events_ability_classes', array( self::class, 'ability_classes' ) );
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/core/booking-venue-settings.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingRepository.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingActivityRepository.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingNotificationService.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingCommunicationService.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingCorrespondenceAutomationService.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingVenueIntervalConflictProvider.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingHoldRepository.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingMutationService.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingEventSyncService.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingEventConversionService.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingMarketingService.php';
		\ExtraChillEvents\Core\BookingMarketingService::register();
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingLifecycle.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingPrivateFileProvider.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/LocalBookingPrivateFileProvider.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingPrivateStorageReadiness.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingPrivateFileProviders.php';
		// Old Events' network adapter may have declared these before Booking deploys.
		if ( ! class_exists( '\ExtraChillEvents\Core\BookingAttachmentPolicy', false ) ) {
			require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingAttachmentPolicy.php';
		}
		if ( ! class_exists( '\ExtraChillEvents\Core\BookingAttachmentReadiness', false ) ) {
			require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingAttachmentReadiness.php';
		}
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingAttachmentRepository.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingAttachmentDeliveryRepository.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingAttachmentService.php';
		if ( ! class_exists( '\ExtraChillEvents\Core\VenueBookingConfig', false ) ) {
			require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/VenueBookingConfig.php';
		}
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingInquiryAdmissionService.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/ArtistBookingInquiryService.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/VenueBookingEmbed.php';
		\ExtraChillEvents\Core\VenueBookingEmbed::register();
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/core/booking-ui.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/core/booking-console.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/TicketReconciliationService.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/TicketSettlementService.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/ShowSettlementService.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingReportingService.php';
		require_once EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Core/BookingPrivacyService.php';
		\ExtraChillEvents\Core\BookingPrivacyService::register();
		\ExtraChillEvents\Core\BookingHoldRepository::register();
		\ExtraChillEvents\Core\BookingCommunicationService::register();
		\ExtraChillEvents\Core\BookingCorrespondenceAutomationService::register();
		\ExtraChillEvents\Core\BookingNotificationService::register();
		new \ExtraChillEvents\Core\BookingVenueIntervalConflictProvider();

		self::$registered = true;
		return true;
	}

	/**
	 * Install booking immediately after the venue schema, including fresh activation.
	 *
	 * @param string $context Lifecycle entry point.
	 */
	public static function install_schema( string $context ): void {
		if ( 'activate' === $context ) {
			\ExtraChillEvents\Core\BookingSchema::install();
		} else {
			\ExtraChillEvents\Core\BookingSchema::maybe_install();
		}
	}

	/**
	 * Insert booking groups at their historical positions in the ability list.
	 *
	 * @param array $abilities Ordered ability class names.
	 * @return array
	 */
	public static function ability_classes( array $abilities ): array {
		if ( class_exists( '\\ExtraChillEvents\\Core\\VenueSchema' ) && \ExtraChillEvents\Core\VenueSchema::is_ready() ) {
			$abilities = self::insert_after( $abilities, array( 'VenueOnboardingAbilities' ), array( 'VenueBookingConfigAbilities' ) );
		}
		if ( \ExtraChillEvents\Core\BookingSchema::is_ready() ) {
			$abilities = self::insert_after(
				$abilities,
				array( 'ManagedVenueVoicesAbilities', 'LocationEventAbilities' ),
				array(
					'VenueBookingAbilities',
					'ArtistBookingInquiryAbilities',
					'BookingAttachmentAbilities',
					'VenueBookingHoldAbilities',
					'VenueBookingMutationAbilities',
					'VenueBookingEventAbilities',
					'VenueBookingCommunicationAbilities',
					'VenueBookingMarketingAbilities',
					'TicketSettlementAbilities',
					'ShowSettlementAbilities',
					'BookingReportingAbilities',
					'BookingPrivacyAbilities',
				)
			);
		}
		foreach ( $abilities as $ability ) {
			$path = EXTRACHILL_BOOKING_PLUGIN_DIR . 'inc/Abilities/' . $ability . '.php';
			if ( is_file( $path ) ) {
				require_once $path;
			}
		}
		return $abilities;
	}

	/**
	 * Insert classes after the first anchor present in the list, or append them.
	 *
	 * @param string[] $abilities Ordered ability class names.
	 * @param string[] $anchors   Candidate anchors in preference order.
	 * @param string[] $classes   Classes to insert.
	 * @return string[]
	 */
	private static function insert_after( array $abilities, array $anchors, array $classes ): array {
		$abilities = array_values( $abilities );
		foreach ( $anchors as $anchor ) {
			$position = array_search( $anchor, $abilities, true );
			if ( is_int( $position ) ) {
				array_splice( $abilities, $position + 1, 0, $classes );
				return $abilities;
			}
		}
		return array_merge( $abilities, $classes );
	}
}
