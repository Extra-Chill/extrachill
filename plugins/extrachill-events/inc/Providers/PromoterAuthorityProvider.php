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
		if ( ! class_exists( \ExtraChill\Users\Teams\TeamMembershipRepository::class ) ) {
			return false;
		}
		foreach ( array( 'PromoterAuthorityRepository', 'PromoterAuthorization', 'PromoterAuthorityService', 'PromoterWorkspace' ) as $class ) {
			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/' . $class . '.php';
		}
		self::$registered = true;
		return true;
	}
}
