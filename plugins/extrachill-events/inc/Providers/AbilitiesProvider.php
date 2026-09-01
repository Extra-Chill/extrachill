<?php
/**
 * Events ability registration.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Registers Events-owned abilities after the WordPress ability API loads. */
final class AbilitiesProvider {

	/**
	 * Whether the lifecycle hook has registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Whether abilities have initialized.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/** Register the ability lifecycle hook once. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;
		add_action( 'plugins_loaded', array( self::class, 'initialize' ), 25 );
	}

	/** Load and instantiate the established ability groups when the API exists. */
	public static function initialize(): void {
		if ( self::$initialized || ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::$initialized = true;
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/abilities/register.php';

		$abilities = array(
			'EventRoundupAbilities',
			'EventLocationAlignmentAbilities',
			'LocationEventAbilities',
		);

		if ( class_exists( '\\ExtraChillEvents\\Core\\BookingSchema' ) && \ExtraChillEvents\Core\BookingSchema::is_ready() ) {
			$abilities = array_merge(
				$abilities,
				array(
					'VenueMembershipAbilities',
					'VenueOnboardingAbilities',
					'VenueBookingConfigAbilities',
					'VenueProfileAbilities',
					'ManagedVenueVoicesAbilities',
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

		if ( class_exists( '\\ExtraChillEvents\\Core\\PromoterAuthoritySchema' ) && \ExtraChillEvents\Core\PromoterAuthoritySchema::is_ready() ) {
			$abilities[] = 'PromoterAuthorityAbilities';
			$abilities[] = 'PromoterVenueGrantAbilities';
			if ( class_exists( '\\ExtraChillEvents\\Core\\BookingSchema' ) && \ExtraChillEvents\Core\BookingSchema::is_ready() ) {
				$abilities[] = 'PromoterWorkspaceAbilities';
			}
		}

		$abilities = array_merge(
			$abilities,
			array(
				'PriorityVenueAbilities',
				'PriorityEventAbilities',
				'CityAbilities',
				'VenueDiscoveryAbilities',
				'VenueQualificationAbilities',
				'VenueAddAbilities',
				'VenueExpansionAbilities',
				'EventSubmissionAbilities',
				'MarketReportAbilities',
				'EventSourceRampAbilities',
				'EventTimeAuditAbilities',
				'QualifyDigestAbilities',
				'ArtistUrlImportAbilities',
			)
		);

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/FlowLocationGuard.php';
		foreach ( $abilities as $ability ) {
			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/' . $ability . '.php';
			$class = '\\ExtraChillEvents\\Abilities\\' . $ability;
			new $class();
		}
	}
}
