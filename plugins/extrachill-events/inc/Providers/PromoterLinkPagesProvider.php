<?php
/**
 * Promoter-owned Link Pages integration bootstrap.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Registers the promoter adapter only after complete standalone API-v3 readiness. */
final class PromoterLinkPagesProvider {

	/** @var bool */
	private static $registered = false;

	/** Defer until the venue adapter's complete API-v3 preflight can run. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'plugins_loaded', array( self::class, 'initialize' ), 31 );
	}

	/** Preflight every promoter registry before mutating any of them. */
	public static function initialize() {
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/PromoterLinkPages.php';
		\ExtraChillEvents\Core\PromoterLinkPages::register_authority_hook();
		$valid = VenueLinkPagesProvider::validate_runtime();
		if ( is_wp_error( $valid ) ) {
			return self::record_error( $valid );
		}
		$registrations = array(
			array( 'ec_can_register_link_page_owner_compatibility_provider', 'ec_register_link_page_owner_compatibility_provider', array( '\\ExtraChillEvents\\Core\\PromoterLinkPages', 'compatibility_provider' ) ),
			array( 'ec_can_register_link_page_operation_provider', 'ec_register_link_page_operation_provider', array( '\\ExtraChillEvents\\Core\\PromoterLinkPages', 'operation_provider' ) ),
			array( 'ec_can_register_link_page_public_projection_provider', 'ec_register_link_page_public_projection_provider', array( '\\ExtraChillEvents\\Core\\PromoterLinkPages', 'public_projection_provider' ) ),
		);
		foreach ( $registrations as $registration ) {
			$result = call_user_func( $registration[0], 'events-promoters', $registration[2] );
			if ( is_wp_error( $result ) ) {
				return self::record_error( $result );
			}
		}
		foreach ( $registrations as $registration ) {
			$result = call_user_func( $registration[1], 'events-promoters', $registration[2] );
			if ( is_wp_error( $result ) ) {
				return self::record_error( $result );
			}
		}
		\ExtraChillEvents\Core\PromoterLinkPages::register_hooks();
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/PromoterLinkPageAbilities.php';
		new \ExtraChillEvents\Abilities\PromoterLinkPageAbilities();
		self::clear_logged_error();
		return true;
	}

	/** Site option recording the last error code that was written to the log. */
	private const LOGGED_ERROR_OPTION = 'extrachill_events_promoter_link_pages_logged_error';

	/**
	 * Publish an operator-visible integration failure.
	 *
	 * The same underlying `WP_Error` is already recorded and logged once by
	 * `VenueLinkPagesProvider::record_error()` (called first, at priority 30,
	 * one tick before this provider runs at priority 31). Without a guard here
	 * this provider would write a second, redundant `error_log()` line for the
	 * exact same failure on every request forever, in addition to the venue
	 * provider's own line — the double-logging this method's own docblock
	 * exists to avoid conflicts with. Log at most once per distinct error code.
	 */
	private static function record_error( \WP_Error $error ) {
		$GLOBALS['extrachill_events_promoter_link_pages_error'] = $error;
		$code = $error->get_error_code();
		if ( get_option( self::LOGGED_ERROR_OPTION, '' ) !== $code ) {
			update_option( self::LOGGED_ERROR_OPTION, $code, false );
			error_log( 'Extra Chill Events promoter Link Pages: ' . $error->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Cross-plugin registration failures must be visible, logged once per distinct error state.
		}
		do_action( 'extrachill_events_promoter_link_pages_error', $error );
		return $error;
	}

	/**
	 * Forget the last logged error so a later recurrence is logged again.
	 *
	 * Without this, the log-once guard would suppress a genuine regression:
	 * once the runtime recovers, the stale error code stays recorded, and an
	 * identical failure later would be silently swallowed instead of surfacing
	 * in `debug.log`. Clearing on success bounds the guard to a single
	 * contiguous failure state rather than the lifetime of the install.
	 */
	private static function clear_logged_error(): void {
		if ( '' !== (string) get_option( self::LOGGED_ERROR_OPTION, '' ) ) {
			delete_option( self::LOGGED_ERROR_OPTION );
		}
	}
}
