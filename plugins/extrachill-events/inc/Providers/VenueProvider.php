<?php
/**
 * Venue-owned dependencies and hooks.
 *
 * Venue infrastructure (schema, memberships, invitations, onboarding, calendar
 * feeds, the venue lock and the canonical-event publication guard) loads here
 * on its own. Feature layers built on venues register afterwards and depend on
 * this layer, never the reverse.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Loads and registers venue infrastructure. */
final class VenueProvider {

	/**
	 * Whether the provider registered during this request.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Load venue dependencies and register venue hooks once.
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

		foreach ( self::dependencies() as $dependency ) {
			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/' . $dependency . '.php';
		}

		\ExtraChillEvents\Core\VenueInvitationDeliveryWorker::register();
		\ExtraChillEvents\Core\VenueCalendarFeedSync::register();
		new \ExtraChillEvents\Core\CanonicalEventPublicationGuard();

		self::$registered = true;
		return true;
	}

	/**
	 * Venue-owned classes under inc/Core, in load order.
	 *
	 * @return string[]
	 */
	public static function dependencies(): array {
		return array(
			'VenueSchema',
			'VenueMembershipRepository',
			'VenueAuthorization',
			'ArtistMappingLock',
			'ArtistAuthorization',
			'VenueMembershipService',
			'VenueInvitationToken',
			'VenueOnboardingRepository',
			'VenueOnboardingService',
			'VenueCalendarFeed',
			'VenueCalendarFeedSync',
			'VenueInvitationDeliveryWorker',
			'VenueLock',
			'VenueProfile',
			'CanonicalEventPublicationGuard',
		);
	}
}
