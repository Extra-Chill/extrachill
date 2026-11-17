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

	/**
	 * Whether registration was deferred until plugins_loaded.
	 *
	 * @var bool
	 */
	private static $deferred = false;

	/** Load promoter authority dependencies once. */
	public static function register(): bool {
		if ( self::$registered ) {
			return true;
		}
		if ( ! class_exists( \ExtraChill\Users\Teams\TeamMembershipRepository::class ) ) {
			// extrachill-users may load after this plugin; retry once every
			// plugin has loaded, ahead of the abilities layer (plugins_loaded:25).
			if ( ! self::$deferred && function_exists( 'add_action' ) && function_exists( 'did_action' ) && ! did_action( 'plugins_loaded' ) ) {
				self::$deferred = true;
				add_action( 'plugins_loaded', array( self::class, 'register_deferred' ), 5 );
			}
			return false;
		}
		foreach ( array( 'PromoterAuthorityRepository', 'PromoterWorkspace' ) as $class ) {
			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/' . $class . '.php';
		}
		self::$registered = true;
		return true;
	}

	/** Retry registration after every plugin has loaded. */
	public static function register_deferred(): void {
		self::register();
	}
}
