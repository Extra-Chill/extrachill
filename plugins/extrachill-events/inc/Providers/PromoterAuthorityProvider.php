<?php
/**
 * Promoter authority dependency registration.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Loads promoter authority independently from venue booking. */
final class PromoterAuthorityProvider {
	/**
	 * Whether dependencies have loaded.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/** Load promoter authority dependencies once. */
	public static function register(): bool {
		if ( self::$registered ) {
			return true;
		}
		foreach ( array( 'PromoterAuthoritySchema', 'PromoterAuthorityRepository', 'PromoterAuthorization', 'PromoterAuthorityService', 'PromoterVenueGrantRepository', 'PromoterVenueAuthorization', 'PromoterVenueGrantService' ) as $class ) {
			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/' . $class . '.php';
		}
		self::$registered = true;
		return true;
	}
}
