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

		if ( ! class_exists( \ExtraChill\Users\Teams\TeamMembershipRepository::class ) ) {
			return false;
		}

		if ( ! function_exists( 'add_action' ) || ! function_exists( 'add_filter' ) ) {
			return false;
		}

		foreach ( self::dependencies() as $dependency ) {
			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/' . $dependency . '.php';
		}

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/venue-settings.php';

		add_action( 'init', array( \ExtraChillEvents\Core\NetworkTeamMembersMigration::class, 'copy_legacy' ), 0 );
		\ExtraChillEvents\Core\TeamCapabilities::register();
		\ExtraChillEvents\Core\VenueInvitationDeliveryWorker::register();
		\ExtraChillEvents\Core\VenueCalendarFeedSync::register();
		new \ExtraChillEvents\Core\CanonicalEventPublicationGuard();

		add_filter( 'datamachine_migrations', static function ( array $migrations ): array {
			if ( ! class_exists( \DataMachine\Core\Database\Migrations\Migration::class ) ) {
				return $migrations;
			}
			$migrations[] = new class extends \DataMachine\Core\Database\Migrations\Migration {
				public function id(): string { return 'extrachill-events.network-team-members'; }
				public function description(): string { return 'Verify network memberships and retire blog-7 membership storage.'; }
				public function inspect(): array { return \ExtraChillEvents\Core\NetworkTeamMembersMigration::inspect(); }
				public function apply( int $limit ): array { return \ExtraChillEvents\Core\NetworkTeamMembersMigration::apply(); }
				public function isComplete(): bool { return ! \ExtraChillEvents\Core\NetworkTeamMembersMigration::exists(); }
			};
			return $migrations;
		} );

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
			'NetworkTeamMembersMigration',
			'VenueSchema',
			'VenueMembershipRepository',
			'TeamAuthorization',
			'TeamCapabilities',
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
