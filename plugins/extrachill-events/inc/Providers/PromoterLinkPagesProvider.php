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
		return true;
	}

	/** Publish an operator-visible integration failure. */
	private static function record_error( \WP_Error $error ) {
		$GLOBALS['extrachill_events_promoter_link_pages_error'] = $error;
		error_log( 'Extra Chill Events promoter Link Pages: ' . $error->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Cross-plugin registration failures must be visible.
		do_action( 'extrachill_events_promoter_link_pages_error', $error );
		return $error;
	}
}
