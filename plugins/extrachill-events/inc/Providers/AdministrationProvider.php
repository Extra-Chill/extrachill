<?php
/**
 * Events administration registration.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Registers network settings, Data Machine settings, and owner-site moderation. */
final class AdministrationProvider {

	/**
	 * Whether administrative hooks have registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/** Register administrative surfaces once. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/admin/network-settings.php';
		\ExtraChillEvents\Admin\NetworkSettings::register();
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/datamachine-settings.php';

		if ( is_admin() ) {
			add_action( 'init', array( self::class, 'register_artist_url_admin' ), 5 );
		}
	}

	/** Register the Artist URL Import moderation queue before its parent menu. */
	public static function register_artist_url_admin(): void {
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/admin/ArtistUrlSubmissionsAdmin.php';
		new \ExtraChillEvents\Admin\ArtistUrlSubmissionsAdmin();
	}
}
