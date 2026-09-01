<?php
/**
 * Events plugin lifecycle and schema registration.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Owns plugin lifecycle hooks without coupling them to optional integrations. */
final class LifecycleProvider {

	/**
	 * Whether lifecycle hooks have registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/** Register lifecycle, localization, and schema hooks once. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_filter( 'ec_feature_ceilings', array( self::class, 'register_feature_ceilings' ) );
		add_action( 'init', array( self::class, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( self::class, 'maybe_install_schema' ), 20 );
		register_activation_hook( EXTRACHILL_EVENTS_PLUGIN_FILE, array( self::class, 'activate' ) );
		register_deactivation_hook( EXTRACHILL_EVENTS_PLUGIN_FILE, array( self::class, 'deactivate' ) );
	}

	/**
	 * Keep venue booking tools on the established internal-team rollout rung.
	 *
	 * @param array $ceilings Existing feature ceilings.
	 * @return array
	 */
	public static function register_feature_ceilings( array $ceilings ): array {
		if ( class_exists( '\\ExtraChillEvents\\Core\\VenueAuthorization' ) ) {
			$ceilings[ \ExtraChillEvents\Core\VenueAuthorization::FEATURE ] = 'team';
		}
		return $ceilings;
	}

	/** Load translations from the established plugin language directory. */
	public static function load_textdomain(): void {
		$plugin_basename = (string) constant( 'EXTRACHILL_EVENTS_PLUGIN_BASENAME' );
		load_plugin_textdomain( 'extrachill-events', false, dirname( $plugin_basename ) . '/languages' );
	}

	/** Install every Events-owned schema during activation. */
	public static function activate(): void {
		\ExtraChillEvents\Core\QualifyVerdictsTable::create_table();
		\ExtraChillEvents\Core\ArtistUrlSubmissionsTable::create_table();
		if ( class_exists( '\\ExtraChillEvents\\Core\\PromoterAuthoritySchema' ) ) {
			\ExtraChillEvents\Core\PromoterAuthoritySchema::install();
		}
		\ExtraChillEvents\Core\BookingSchema::install();
		\ExtraChillEvents\Core\VendorRequestSchema::install();
		flush_rewrite_rules();
	}

	/** Flush routes when the plugin is deactivated. */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/** Idempotently install schemas after providers have loaded their classes. */
	public static function maybe_install_schema(): void {
		if ( class_exists( '\\ExtraChillEvents\\Core\\QualifyVerdictsTable' ) ) {
			\ExtraChillEvents\Core\QualifyVerdictsTable::maybe_install();
		}
		if ( class_exists( '\\ExtraChillEvents\\Core\\ArtistUrlSubmissionsTable' ) ) {
			\ExtraChillEvents\Core\ArtistUrlSubmissionsTable::maybe_install();
		}
		if ( class_exists( '\\ExtraChillEvents\\Core\\BookingSchema' ) ) {
			\ExtraChillEvents\Core\BookingSchema::maybe_install();
		}
		if ( class_exists( '\\ExtraChillEvents\\Core\\PromoterAuthoritySchema' ) ) {
			\ExtraChillEvents\Core\PromoterAuthoritySchema::maybe_install();
		}
		if ( class_exists( '\\ExtraChillEvents\\Core\\VendorRequestSchema' ) ) {
			\ExtraChillEvents\Core\VendorRequestSchema::maybe_install();
		}
	}
}
