<?php
/**
 * Attach Artist Platform to the Extra Chill Link Pages runtime.
 *
 * extrachill-link-pages is the only provider of the generic ec_*link_page*
 * API. Artist Platform declares it as a hard dependency (Requires Plugins) and
 * carries no copy of its own. This file validates the runtime contract Artist
 * Platform consumes and registers the artist adapters into it. When the
 * runtime is missing or incompatible, the adapters are skipped and an admin
 * notice explains why.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/** Unregistered post type used when the Link Pages runtime is unavailable. */
const EXTRACHILL_ARTIST_PLATFORM_NO_LINK_PAGE_POST_TYPE = 'ec_link_page_missing';

/**
 * Resolve the Link Page post type at the point of use.
 *
 * Every consumer in this plugin calls this instead of a post type literal. It
 * is deliberately never memoized: the runtime resolves the post type from the
 * current storage blog, and the storage blog can change mid-request
 * (migration dry runs, a gate flip). Without the runtime there is no Link
 * Page post type, so this returns an unregistered sentinel that matches no
 * post. An empty string would not: WP_Query treats an empty post_type as
 * 'post'.
 *
 * @return string
 */
function extrachill_artist_platform_link_page_post_type() {
	return function_exists( 'ec_link_page_post_type' ) ? ec_link_page_post_type() : EXTRACHILL_ARTIST_PLATFORM_NO_LINK_PAGE_POST_TYPE;
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
		'ec_can_register_link_page_owner_compatibility_provider' => array(
			'total'    => 3,
			'required' => 2,
		),
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
		'ec_can_register_link_page_operation_provider'     => array(
			'total'    => 3,
			'required' => 2,
		),
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
		'ec_save_link_page_persistence_composed'           => array(
			'total'    => 3,
			'required' => 3,
		),
		'ec_create_owned_link_page'                        => array(
			'total'    => 4,
			'required' => 3,
		),
		'ec_provision_owned_link_page'                     => array(
			'total'    => 5,
			'required' => 3,
		),
		'ec_provision_owned_link_page_composed'            => array(
			'total'    => 6,
			'required' => 4,
		),
		'ec_invoke_link_page_provision_precondition'       => array(
			'total'    => 2,
			'required' => 2,
		),
		'ec_create_owned_link_page_unlocked'               => array(
			'total'    => 4,
			'required' => 3,
		),
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
		'ec_can_register_link_page_public_projection_provider' => array(
			'total'    => 3,
			'required' => 2,
		),
		'ec_sanitize_link_page_public_projection_snapshot' => array(
			'total'    => 1,
			'required' => 1,
		),
		'ec_save_link_page_public_projection_snapshot'     => array(
			'total'    => 3,
			'required' => 3,
		),
		'ec_read_link_page_public_projection_snapshot'     => array(
			'total'    => 2,
			'required' => 1,
		),
		'ec_render_stored_link_page_social_links'          => array(
			'total'    => 1,
			'required' => 1,
		),
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
		'ec_link_page_migration_participant_registry'      => array(
			'total'    => 0,
			'required' => 0,
		),
		'ec_register_link_page_migration_participant'      => array(
			'total'    => 4,
			'required' => 3,
		),
		'ec_link_page_post_type'                           => array(
			'total'    => 1,
			'required' => 0,
		),
	);
}

/**
 * Read one of the runtime's contract constants at run time.
 *
 * The constants are the contract being validated, so their values must come
 * from the loaded runtime rather than be folded in by static analysis.
 *
 * @param string $name Constant name.
 * @return mixed Constant value, or null when undefined.
 */
function extrachill_artist_platform_link_pages_runtime_constant( $name ) {
	return defined( $name ) ? constant( $name ) : null;
}

/**
 * Validate the generic API surface consumed by the artist adapters.
 *
 * @return true|WP_Error
 */
function extrachill_artist_platform_validate_link_pages_runtime() {
	if ( ! defined( 'EC_LINK_PAGE_OWNER_META_KEY' ) ) {
		return new WP_Error( 'extrachill_link_pages_runtime_missing', 'Extra Chill Artist Platform requires the Extra Chill Link Pages plugin. Activate extrachill-link-pages to enable artist Link Pages.' );
	}
	if ( '_ec_link_page_owner_reference' !== extrachill_artist_platform_link_pages_runtime_constant( 'EC_LINK_PAGE_OWNER_META_KEY' ) ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incompatible', 'The Extra Chill Link Pages runtime uses an incompatible storage contract.' );
	}
	if ( ! defined( 'EC_LINK_PAGES_RUNTIME_API_VERSION' ) ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incomplete', 'The Extra Chill Link Pages runtime did not declare its API version.' );
	}
	if ( '4' !== extrachill_artist_platform_link_pages_runtime_constant( 'EC_LINK_PAGES_RUNTIME_API_VERSION' ) ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incompatible', 'The Extra Chill Link Pages runtime API version is not supported.' );
	}
	if ( ! function_exists( 'ec_link_page_post_type' ) ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incomplete', 'The Extra Chill Link Pages runtime did not expose its post type resolver.' );
	}
	if ( ! function_exists( 'ec_validate_link_pages_runtime' ) ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incomplete', 'The Extra Chill Link Pages runtime did not expose complete readiness validation.' );
	}
	$standalone_valid = ec_validate_link_pages_runtime();
	if ( is_wp_error( $standalone_valid ) ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incompatible', $standalone_valid->get_error_message(), array( 'cause' => $standalone_valid->get_error_code() ) );
	}

	foreach ( extrachill_artist_platform_link_pages_runtime_signatures() as $function => $signature ) {
		if ( ! function_exists( $function ) ) {
			return new WP_Error( 'extrachill_link_pages_runtime_incomplete', 'The Extra Chill Link Pages runtime did not load its complete generic API.' );
		}
		$reflection = new ReflectionFunction( $function );
		if ( $signature['total'] !== $reflection->getNumberOfParameters() || $signature['required'] !== $reflection->getNumberOfRequiredParameters() ) {
			return new WP_Error( 'extrachill_link_pages_runtime_incompatible', 'The Extra Chill Link Pages runtime exposes an incompatible generic function signature.' );
		}
	}
	if ( ! function_exists( 'ec_link_pages_runtime_ready' ) ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incomplete', 'The Extra Chill Link Pages runtime did not expose its readiness marker.' );
	}
	$readiness = new ReflectionFunction( 'ec_link_pages_runtime_ready' );
	if ( 0 !== $readiness->getNumberOfParameters() || 0 !== $readiness->getNumberOfRequiredParameters() ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incompatible', 'The Extra Chill Link Pages runtime exposes an incompatible readiness marker.' );
	}
	try {
		// @phpstan-ignore phpstan.arguments.count (arity is verified via ReflectionFunction immediately above; dependency signatures may vary by runtime version.)
		$ready = ec_link_pages_runtime_ready();
	} catch ( Throwable $throwable ) {
		$ready = false;
	}
	if ( true !== $ready ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incomplete', 'The Extra Chill Link Pages runtime reported that it is not ready.' );
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
	require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/storage-migration.php';
	require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/artist-public-runtime-adapter.php';
	add_filter( 'ec_link_page_public_query_var', 'ec_artist_link_page_public_query_var' );
	add_filter( 'ec_link_page_public_query_vars', 'ec_artist_link_page_public_query_vars' );
	add_filter( 'ec_link_page_public_exclusions', 'ec_artist_link_page_public_exclusions' );
	add_filter( 'ec_link_page_public_special_route', 'ec_artist_link_page_public_special_route', 10, 2 );

	$owner_result = ec_register_link_page_owner_compatibility_provider( 'artist-platform', 'ec_artist_link_page_owner_compatibility_provider' );
	if ( is_wp_error( $owner_result ) ) {
		return $owner_result;
	}
	$operation_result = ec_register_link_page_operation_provider( 'artist-platform', 'ec_artist_link_page_operation_provider' );
	if ( is_wp_error( $operation_result ) ) {
		return $operation_result;
	}
	$migration_result = ec_artist_register_link_page_migration_adapter();
	if ( is_wp_error( $migration_result ) ) {
		return $migration_result;
	}
	$projection_result = ec_register_link_page_public_projection_provider( 'artist-platform', 'ec_artist_link_page_public_projection_provider' );
	if ( is_wp_error( $projection_result ) ) {
		return $projection_result;
	}

	$registered = true;
	return true;
}

/**
 * Attach the artist adapters to the Link Pages runtime.
 *
 * The runtime contract requires generic APIs to be available by
 * plugins_loaded priority 20. Its CPT registration remains its own init hook.
 * A missing or incompatible runtime skips the adapters and raises an admin
 * notice instead of loading a fallback copy.
 *
 * @return true|WP_Error
 */
function extrachill_artist_platform_boot_link_pages_runtime() {
	$result = extrachill_artist_platform_register_link_page_adapters();
	if ( is_wp_error( $result ) ) {
		$GLOBALS['extrachill_artist_platform_link_pages_runtime_error'] = $result;
		add_action( 'admin_notices', 'extrachill_artist_platform_link_pages_runtime_notice' );
	}

	return $result;
}

/**
 * Display an explicit operator-facing error for a missing or incomplete runtime.
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
