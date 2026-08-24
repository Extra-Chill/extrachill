<?php
/**
 * Venue-owned Link Pages integration bootstrap.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Defers Events adapters until the standalone API-v3 runtime is complete.
 *
 * TODO: Add the native plugin dependency after Link Pages PR #4 is merged,
 * released, and installed across the deployment stack.
 */
final class VenueLinkPagesProvider {

	private const PLUGIN = 'extrachill-link-pages/extrachill-link-pages.php';

	/**
	 * Whether the deferred hook registered in this request.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/** Attach the deferred bootstrap once. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'plugins_loaded', array( self::class, 'initialize' ), 30 );
	}

	/** Register one provider for each standalone extension registry. */
	public static function initialize() {
		$valid = self::validate_runtime();
		if ( is_wp_error( $valid ) ) {
			self::record_error( $valid );
			return $valid;
		}

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/VenueLinkPages.php';
		$registrations = array(
			array( 'ec_can_register_link_page_owner_compatibility_provider', 'ec_register_link_page_owner_compatibility_provider', array( '\\ExtraChillEvents\\Core\\VenueLinkPages', 'compatibility_provider' ) ),
			array( 'ec_can_register_link_page_operation_provider', 'ec_register_link_page_operation_provider', array( '\\ExtraChillEvents\\Core\\VenueLinkPages', 'operation_provider' ) ),
			array( 'ec_can_register_link_page_public_projection_provider', 'ec_register_link_page_public_projection_provider', array( '\\ExtraChillEvents\\Core\\VenueLinkPages', 'public_projection_provider' ) ),
		);
		foreach ( $registrations as $registration ) {
			$result = call_user_func( $registration[0], 'events-venues', $registration[2] );
			if ( is_wp_error( $result ) ) {
				self::record_error( $result );
				return $result;
			}
		}
		foreach ( $registrations as $registration ) {
			$result = call_user_func( $registration[1], 'events-venues', $registration[2] );
			if ( is_wp_error( $result ) ) {
				self::record_error( $result );
				return $result;
			}
		}

		\ExtraChillEvents\Core\VenueLinkPages::register_hooks();
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/VenueLinkPageAbilities.php';
		new \ExtraChillEvents\Abilities\VenueLinkPageAbilities();
		return true;
	}

	/** Validate configured activation and the complete standalone API-v3 marker. */
	public static function validate_runtime() {
		$active         = (array) get_option( 'active_plugins', array() );
		$network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
		if ( ! in_array( self::PLUGIN, $active, true ) && ! isset( $network_active[ self::PLUGIN ] ) ) {
			return new \WP_Error( 'venue_link_pages_runtime_not_configured', __( 'Extra Chill Link Pages must be active before venue Link Pages can load.', 'extrachill-events' ) );
		}
		$runtime_version = defined( 'EC_LINK_PAGES_RUNTIME_API_VERSION' ) ? constant( 'EC_LINK_PAGES_RUNTIME_API_VERSION' ) : null;
		if ( '3' !== $runtime_version ) {
			return new \WP_Error( 'venue_link_pages_runtime_incomplete', __( 'The configured Extra Chill Link Pages API-v3 runtime is incomplete.', 'extrachill-events' ) );
		}
		$signatures        = array(
			'ec_validate_link_pages_runtime'               => array( 1, 0 ),
			'ec_link_pages_runtime_ready'                  => array( 0, 0 ),
			'ec_get_link_page_storage_blog_id'             => array( 0, 0 ),
			'ec_can_register_link_page_owner_compatibility_provider' => array( 3, 2 ),
			'ec_register_link_page_owner_compatibility_provider' => array( 3, 2 ),
			'ec_can_register_link_page_operation_provider' => array( 3, 2 ),
			'ec_register_link_page_operation_provider'     => array( 3, 2 ),
			'ec_can_register_link_page_public_projection_provider' => array( 3, 2 ),
			'ec_register_link_page_public_projection_provider' => array( 3, 2 ),
			'ec_provision_owned_link_page_composed'        => array( 6, 4 ),
			'ec_invoke_link_page_provision_precondition'   => array( 2, 2 ),
			'ec_save_link_page_public_projection_snapshot' => array( 3, 3 ),
			'ec_read_link_page_public_projection_snapshot' => array( 2, 1 ),
			'ec_with_link_page_storage_blog'               => array( 1, 1 ),
			'ec_with_link_page_lock_scope'                 => array( 3, 2 ),
			'ec_get_link_page_id_for_owner'                => array( 2, 1 ),
			'ec_normalize_link_page_owner_reference'       => array( 1, 1 ),
			'ec_parse_link_page_owner_reference'           => array( 1, 1 ),
			'ec_get_link_page_owner'                       => array( 1, 1 ),
			'ec_read_link_page_persistence'                => array( 2, 1 ),
			'ec_save_link_page_persistence_composed'       => array( 3, 3 ),
			'ec_snapshot_link_page_meta'                   => array( 2, 2 ),
			'ec_write_link_page_meta'                      => array( 4, 3 ),
			'ec_restore_link_page_meta_snapshots'          => array( 2, 2 ),
			'ec_get_link_page_public_url'                  => array( 1, 1 ),
			'ec_purge_link_page_after_mutation'            => array( 1, 1 ),
			'ec_get_stored_link_page_owner_references'     => array( 1, 1 ),
			'ec_read_link_page'                            => array( 1, 1 ),
			'ec_save_link_page'                            => array( 2, 2 ),
			'ec_link_page_id_meta_keys'                    => array( 0, 0 ),
		);
		$defined_functions = get_defined_functions();
		$user_functions    = array_map( 'strtolower', $defined_functions['user'] );
		foreach ( $signatures as $function => $arity ) {
			if ( ! in_array( strtolower( $function ), $user_functions, true ) ) {
				return new \WP_Error( 'venue_link_pages_runtime_incomplete', __( 'The configured Extra Chill Link Pages API-v3 runtime is incomplete.', 'extrachill-events' ) );
			}
			$reflection = new \ReflectionFunction( $function );
			if ( $arity[0] !== $reflection->getNumberOfParameters() || $arity[1] !== $reflection->getNumberOfRequiredParameters() ) {
				return new \WP_Error( 'venue_link_pages_runtime_incompatible', __( 'The configured Extra Chill Link Pages API-v3 runtime has an incompatible signature.', 'extrachill-events' ), array( 'function' => $function ) );
			}
		}
		$storage_callback = 'ec_get_link_page_storage_blog_id';
		if ( ! call_user_func( $storage_callback ) ) {
			return new \WP_Error( 'venue_link_pages_storage_unavailable', __( 'The canonical Link Page storage blog is unavailable.', 'extrachill-events' ) );
		}
		$validation_callback = 'ec_validate_link_pages_runtime';
		$readiness_callback  = 'ec_link_pages_runtime_ready';
		$valid               = call_user_func( $validation_callback );
		if ( is_wp_error( $valid ) ) {
			return new \WP_Error( 'venue_link_pages_runtime_incompatible', $valid->get_error_message(), array( 'cause' => $valid->get_error_code() ) );
		}
		return true === call_user_func( $readiness_callback ) ? true : new \WP_Error( 'venue_link_pages_runtime_incomplete', __( 'The configured Extra Chill Link Pages runtime is not ready.', 'extrachill-events' ) );
	}

	/** Make integration failure visible without partially registering adapters. */
	private static function record_error( \WP_Error $error ): void {
		$GLOBALS['extrachill_events_venue_link_pages_error'] = $error;
		error_log( 'Extra Chill Events venue Link Pages: ' . $error->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A partial cross-plugin runtime must be operator-visible.
		add_action( 'admin_notices', array( self::class, 'error_notice' ) );
		add_action( 'network_admin_notices', array( self::class, 'error_notice' ) );
		do_action( 'extrachill_events_venue_link_pages_error', $error );
	}

	/** Render the stored integration error. */
	public static function error_notice(): void {
		$error = $GLOBALS['extrachill_events_venue_link_pages_error'] ?? null;
		if ( is_wp_error( $error ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error->get_error_message() ) );
		}
	}
}
