<?php
/**
 * Coordinate the bundled and standalone Link Pages runtimes.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return whether WordPress is configured to load the standalone runtime.
 *
 * This reads configuration instead of runtime symbols because Artist Platform
 * loads before the standalone plugin in the normal plugin load order.
 *
 * @return bool
 */
function extrachill_artist_platform_uses_external_link_pages_runtime() {
	$plugin         = 'extrachill-link-pages/extrachill-link-pages.php';
	$active_plugins = (array) get_option( 'active_plugins', array() );
	if ( in_array( $plugin, $active_plugins, true ) ) {
		return true;
	}

	$network_active = (array) get_site_option( 'active_sitewide_plugins', array() );
	return isset( $network_active[ $plugin ] );
}

if ( ! extrachill_artist_platform_uses_external_link_pages_runtime() ) {
	if ( ! defined( 'EC_LINK_PAGE_POST_TYPE' ) ) {
		define( 'EC_LINK_PAGE_POST_TYPE', 'artist_link_page' );
	}
	if ( ! defined( 'EC_LINK_PAGE_OWNER_META_KEY' ) ) {
		define( 'EC_LINK_PAGE_OWNER_META_KEY', '_ec_link_page_owner_reference' );
	}
}

/**
 * Return the exact generic function signatures consumed by Artist Platform.
 *
 * @return array<string,array{total:int,required:int}>
 */
function extrachill_artist_platform_link_pages_runtime_signatures() {
	return array(
		'ec_link_page_owner_compatibility_registry'        => array(
			'total'    => 0,
			'required' => 0,
		),
		'ec_register_link_page_owner_compatibility_provider' => array(
			'total'    => 3,
			'required' => 2,
		),
		'ec_can_register_link_page_owner_compatibility_provider' => array( 'total' => 3, 'required' => 2 ),
		'ec_parse_link_page_owner_reference'               => array(
			'total'    => 1,
			'required' => 1,
		),
		'ec_format_link_page_owner_reference'              => array(
			'total'    => 1,
			'required' => 1,
		),
		'ec_normalize_link_page_owner_reference'           => array(
			'total'    => 1,
			'required' => 1,
		),
		'ec_get_stored_link_page_owner_references'         => array(
			'total'    => 1,
			'required' => 1,
		),
		'ec_validate_link_page_owner_compatibility_claim'  => array(
			'total'    => 3,
			'required' => 3,
		),
		'ec_restore_link_page_owner_provider_context'      => array(
			'total'    => 3,
			'required' => 3,
		),
		'ec_invoke_link_page_owner_compatibility_provider' => array(
			'total'    => 3,
			'required' => 3,
		),
		'ec_collect_raw_link_page_owner_compatibility_claims' => array(
			'total'    => 2,
			'required' => 2,
		),
		'ec_reconcile_link_page_owner_candidate'           => array(
			'total'    => 2,
			'required' => 2,
		),
		'ec_collect_link_page_owner_compatibility_claims'  => array(
			'total'    => 2,
			'required' => 2,
		),
		'ec_get_link_page_owner'                           => array(
			'total'    => 1,
			'required' => 1,
		),
		'ec_get_link_page_id_for_owner'                    => array(
			'total'    => 2,
			'required' => 1,
		),
		'ec_validate_link_page_owner_candidate_ids'        => array(
			'total'    => 1,
			'required' => 1,
		),
		'ec_assign_link_page_owner'                        => array(
			'total'    => 3,
			'required' => 2,
		),
		'ec_compensate_link_page_owner_assignment'         => array(
			'total'    => 4,
			'required' => 4,
		),
		'ec_halt_link_page_owner_backfill'                 => array(
			'total'    => 4,
			'required' => 4,
		),
		'ec_backfill_link_page_owner_references'           => array(
			'total'    => 2,
			'required' => 0,
		),
		'ec_link_page_operation_provider_registry'         => array(
			'total'    => 0,
			'required' => 0,
		),
		'ec_register_link_page_operation_provider'         => array(
			'total'    => 3,
			'required' => 2,
		),
		'ec_can_register_link_page_operation_provider'     => array( 'total' => 3, 'required' => 2 ),
		'ec_resolve_link_page_operation_target'            => array(
			'total'    => 1,
			'required' => 1,
		),
		'ec_invoke_link_page_operation_callback'           => array(
			'total'    => 2,
			'required' => 2,
		),
		'ec_get_link_page_operation_provider'              => array(
			'total'    => 1,
			'required' => 1,
		),
		'ec_prepare_link_page_operation'                   => array(
			'total'    => 2,
			'required' => 2,
		),
		'ec_read_link_page'                                => array(
			'total'    => 1,
			'required' => 1,
		),
		'ec_save_link_page'                                => array(
			'total'    => 2,
			'required' => 2,
		),
		'ec_link_page_defaults'                            => array(
			'total'    => 0,
			'required' => 0,
		),
		'ec_link_page_defaults_for'                        => array(
			'total'    => 1,
			'required' => 1,
		),
		'ec_link_page_default'                             => array(
			'total'    => 3,
			'required' => 2,
		),
		'ec_sanitize_link_page_links'                      => array(
			'total'    => 2,
			'required' => 1,
		),
		'ec_sanitize_link_page_css_vars'                   => array(
			'total'    => 2,
			'required' => 1,
		),
		'ec_sanitize_link_page_settings'                   => array(
			'total'    => 1,
			'required' => 1,
		),
		'ec_read_link_page_persistence'                    => array(
			'total'    => 2,
			'required' => 1,
		),
		'ec_save_link_page_persistence'                    => array(
			'total'    => 2,
			'required' => 2,
		),
		'ec_save_link_page_persistence_composed'           => array( 'total' => 3, 'required' => 3 ),
		'ec_create_owned_link_page'                        => array(
			'total'    => 4,
			'required' => 3,
		),
		'ec_provision_owned_link_page'                     => array( 'total' => 5, 'required' => 3 ),
		'ec_provision_owned_link_page_composed'            => array( 'total' => 6, 'required' => 4 ),
		'ec_invoke_link_page_provision_precondition'       => array( 'total' => 2, 'required' => 2 ),
		'ec_create_owned_link_page_unlocked'               => array( 'total' => 4, 'required' => 3 ),
		'ec_with_link_page_lock_scope'                     => array(
			'total'    => 3,
			'required' => 2,
		),
		'ec_link_page_public_projection_registry'          => array(
			'total'    => 0,
			'required' => 0,
		),
		'ec_register_link_page_public_projection_provider' => array(
			'total'    => 3,
			'required' => 2,
		),
		'ec_can_register_link_page_public_projection_provider' => array( 'total' => 3, 'required' => 2 ),
		'ec_sanitize_link_page_public_projection_snapshot' => array( 'total' => 1, 'required' => 1 ),
		'ec_save_link_page_public_projection_snapshot'     => array( 'total' => 3, 'required' => 3 ),
		'ec_read_link_page_public_projection_snapshot'     => array( 'total' => 2, 'required' => 1 ),
		'ec_render_stored_link_page_social_links'          => array( 'total' => 1, 'required' => 1 ),
		'ec_get_link_page_public_projection'               => array(
			'total'    => 2,
			'required' => 1,
		),
		'ec_render_link_page_public_components'            => array(
			'total'    => 2,
			'required' => 2,
		),
		'ec_get_link_page_public_url'                      => array(
			'total'    => 1,
			'required' => 1,
		),
		'ec_link_page_public_urls'                         => array(
			'total'    => 1,
			'required' => 1,
		),
	);
}

/**
 * Validate the generic API surface consumed by the artist adapters.
 *
 * @return true|WP_Error
 */
function extrachill_artist_platform_validate_link_pages_runtime() {
	$external = extrachill_artist_platform_uses_external_link_pages_runtime();
	if ( ! defined( 'EC_LINK_PAGE_POST_TYPE' ) || ! defined( 'EC_LINK_PAGE_OWNER_META_KEY' ) ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incomplete', 'The configured Extra Chill Link Pages runtime did not load its complete generic API.' );
	}
	if ( 'artist_link_page' !== EC_LINK_PAGE_POST_TYPE || '_ec_link_page_owner_reference' !== EC_LINK_PAGE_OWNER_META_KEY ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incompatible', 'The configured Extra Chill Link Pages runtime uses an incompatible storage contract.' );
	}
	if ( $external && ! defined( 'EC_LINK_PAGES_RUNTIME_API_VERSION' ) ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incomplete', 'The configured Extra Chill Link Pages runtime did not declare its API version.' );
	}
	if ( $external && '3' !== EC_LINK_PAGES_RUNTIME_API_VERSION ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incompatible', 'The configured Extra Chill Link Pages runtime API version is not supported.' );
	}
	if ( $external ) {
		if ( ! function_exists( 'ec_validate_link_pages_runtime' ) ) {
			return new WP_Error( 'extrachill_link_pages_runtime_incomplete', 'The configured Extra Chill Link Pages runtime did not expose complete readiness validation.' );
		}
		$standalone_valid = ec_validate_link_pages_runtime();
		if ( is_wp_error( $standalone_valid ) ) {
			return new WP_Error( 'extrachill_link_pages_runtime_incompatible', $standalone_valid->get_error_message(), array( 'cause' => $standalone_valid->get_error_code() ) );
		}
	}

	$signatures = extrachill_artist_platform_link_pages_runtime_signatures();
	if ( ! $external ) {
		$signatures = array_diff_key( $signatures, array_flip( array( 'ec_can_register_link_page_owner_compatibility_provider', 'ec_can_register_link_page_operation_provider', 'ec_save_link_page_persistence_composed', 'ec_provision_owned_link_page', 'ec_provision_owned_link_page_composed', 'ec_invoke_link_page_provision_precondition', 'ec_create_owned_link_page_unlocked', 'ec_can_register_link_page_public_projection_provider', 'ec_sanitize_link_page_public_projection_snapshot', 'ec_save_link_page_public_projection_snapshot', 'ec_read_link_page_public_projection_snapshot', 'ec_render_stored_link_page_social_links' ) ) );
		$signatures = array_slice( $signatures, 0, 27, true );
	}
	foreach ( $signatures as $function => $signature ) {
		if ( ! function_exists( $function ) ) {
			return new WP_Error( 'extrachill_link_pages_runtime_incomplete', 'The configured Extra Chill Link Pages runtime did not load its complete generic API.' );
		}
		$reflection = new ReflectionFunction( $function );
		if ( $signature['total'] !== $reflection->getNumberOfParameters() || $signature['required'] !== $reflection->getNumberOfRequiredParameters() ) {
			return new WP_Error( 'extrachill_link_pages_runtime_incompatible', 'The configured Extra Chill Link Pages runtime exposes an incompatible generic function signature.' );
		}
	}
	if ( $external && ! function_exists( 'ec_link_pages_runtime_ready' ) ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incomplete', 'The configured Extra Chill Link Pages runtime did not expose its readiness marker.' );
	}
	if ( function_exists( 'ec_link_pages_runtime_ready' ) ) {
		$readiness = new ReflectionFunction( 'ec_link_pages_runtime_ready' );
		if ( 0 !== $readiness->getNumberOfParameters() || 0 !== $readiness->getNumberOfRequiredParameters() ) {
			return new WP_Error( 'extrachill_link_pages_runtime_incompatible', 'The configured Extra Chill Link Pages runtime exposes an incompatible readiness marker.' );
		}
		try {
			$ready = ec_link_pages_runtime_ready();
		} catch ( Throwable $throwable ) {
			$ready = false;
		}
		if ( true !== $ready ) {
			return new WP_Error( 'extrachill_link_pages_runtime_incomplete', 'The configured Extra Chill Link Pages runtime reported that it is not ready.' );
		}
	}

	return true;
}

/**
 * Register Artist Platform's owner adapters exactly once.
 *
 * @return true|WP_Error
 */
function extrachill_artist_platform_register_link_page_adapters() {
	static $registered = false;
	if ( $registered ) {
		return true;
	}

	$valid = extrachill_artist_platform_validate_link_pages_runtime();
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/artist-owner-compatibility.php';
	require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/artist-owner-operations.php';
	if ( extrachill_artist_platform_uses_external_link_pages_runtime() ) {
		require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/artist-public-runtime-adapter.php';
	}
	if ( extrachill_artist_platform_uses_external_link_pages_runtime() ) {
		add_filter( 'ec_link_page_public_query_var', 'ec_artist_link_page_public_query_var' );
		add_filter( 'ec_link_page_public_query_vars', 'ec_artist_link_page_public_query_vars' );
		add_filter( 'ec_link_page_public_exclusions', 'ec_artist_link_page_public_exclusions' );
		add_filter( 'ec_link_page_public_special_route', 'ec_artist_link_page_public_special_route', 10, 2 );
	}

	$owner_result = ec_register_link_page_owner_compatibility_provider( 'artist-platform', 'ec_artist_link_page_owner_compatibility_provider' );
	if ( is_wp_error( $owner_result ) ) {
		return $owner_result;
	}
	$operation_result = ec_register_link_page_operation_provider( 'artist-platform', 'ec_artist_link_page_operation_provider' );
	if ( is_wp_error( $operation_result ) ) {
		return $operation_result;
	}
	if ( extrachill_artist_platform_uses_external_link_pages_runtime() ) {
		$projection_result = ec_register_link_page_public_projection_provider( 'artist-platform', 'ec_artist_link_page_public_projection_provider' );
		if ( is_wp_error( $projection_result ) ) {
			return $projection_result;
		}
	}

	$registered = true;
	return true;
}

/**
 * Load the rolling fallback when needed, then attach artist adapters.
 *
 * The standalone runtime contract requires generic APIs to be available by
 * plugins_loaded priority 20. Its CPT registration remains its own init hook.
 *
 * @return true|WP_Error
 */
function extrachill_artist_platform_boot_link_pages_runtime() {
	if ( ! extrachill_artist_platform_uses_external_link_pages_runtime() ) {
		require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/owner-reference.php';
		require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/operations.php';
	}

	$result = extrachill_artist_platform_register_link_page_adapters();
	if ( is_wp_error( $result ) ) {
		$GLOBALS['extrachill_artist_platform_link_pages_runtime_error'] = $result;
		add_action( 'admin_notices', 'extrachill_artist_platform_link_pages_runtime_notice' );
	}

	return $result;
}

/**
 * Display an explicit operator-facing error for an incomplete runtime.
 *
 * @return void
 */
function extrachill_artist_platform_link_pages_runtime_notice() {
	$error = $GLOBALS['extrachill_artist_platform_link_pages_runtime_error'] ?? null;
	if ( ! is_wp_error( $error ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $error->get_error_message() )
	);
}
