<?php
/**
 * Attach Artist Platform to the Extra Chill Link Pages runtime.
 *
 * extrachill-link-pages is the only provider of the generic ec_*link_page*
 * API. Artist Platform declares it as a hard dependency (Requires Plugins) and
 * carries no copy of its own. This file asks the runtime whether it is valid
 * and ready, then registers the artist adapters into it. When the runtime is
 * missing, invalid or not ready, the adapters are skipped and an admin notice
 * explains why.
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
 * current storage blog. Without the runtime there is no Link
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
 * Ask the Link Pages runtime whether it is usable.
 *
 * The runtime validates its own function contract, storage contract and API
 * version in ec_validate_link_pages_runtime(). Artist Platform ships from the
 * same monorepo commit, so it trusts that answer instead of keeping its own
 * copy of the runtime's signatures.
 *
 * @return true|WP_Error
 */
function extrachill_artist_platform_validate_link_pages_runtime() {
	if ( ! function_exists( 'ec_validate_link_pages_runtime' ) ) {
		return new WP_Error( 'extrachill_link_pages_runtime_missing', 'Extra Chill Artist Platform requires the Extra Chill Link Pages plugin. Activate extrachill-link-pages to enable artist Link Pages.' );
	}
	$valid = ec_validate_link_pages_runtime();
	if ( is_wp_error( $valid ) ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incompatible', $valid->get_error_message(), array( 'cause' => $valid->get_error_code() ) );
	}
	if ( ! function_exists( 'ec_link_pages_runtime_ready' ) || true !== ec_link_pages_runtime_ready() ) {
		return new WP_Error( 'extrachill_link_pages_runtime_incomplete', 'The Extra Chill Link Pages runtime reported that it is not ready.' );
	}
	if ( ! function_exists( 'ec_get_link_page_storage_blog_id' ) || ! ec_get_link_page_storage_blog_id() ) {
		return new WP_Error( 'extrachill_link_pages_storage_unavailable', 'The canonical Link Page storage blog is unavailable.' );
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

	require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/artist-owner-operations.php';
	require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/artist-public-runtime-adapter.php';
	add_filter( 'ec_link_page_public_query_var', 'ec_artist_link_page_public_query_var' );
	add_filter( 'ec_link_page_public_query_vars', 'ec_artist_link_page_public_query_vars' );
	add_filter( 'ec_link_page_public_exclusions', 'ec_artist_link_page_public_exclusions' );
	add_filter( 'ec_link_page_public_special_route', 'ec_artist_link_page_public_special_route', 10, 2 );

	$operation_result = ec_register_link_page_operation_provider( 'artist-platform', 'ec_artist_link_page_operation_provider' );
	if ( is_wp_error( $operation_result ) ) {
		return $operation_result;
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
