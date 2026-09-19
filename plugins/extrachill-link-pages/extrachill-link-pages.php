<?php
/**
 * Plugin Name: Extra Chill Link Pages
 * Plugin URI: https://extrachill.com
 * Description: Owner-neutral Link Page storage and operation runtime for the Extra Chill network.
 * Version: 0.1.0
 * Author: Extra Chill
 * Author URI: https://extrachill.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-link-pages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 *
 * @package ExtraChillLinkPages
 */

defined( 'ABSPATH' ) || exit;

defined( 'EXTRACHILL_LINK_PAGES_VERSION' ) || define( 'EXTRACHILL_LINK_PAGES_VERSION', '0.1.0' );
defined( 'EXTRACHILL_LINK_PAGES_PLUGIN_FILE' ) || define( 'EXTRACHILL_LINK_PAGES_PLUGIN_FILE', __FILE__ );
defined( 'EXTRACHILL_LINK_PAGES_PLUGIN_DIR' ) || define( 'EXTRACHILL_LINK_PAGES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'EXTRACHILL_LINK_PAGES_PLUGIN_BASENAME' ) || define( 'EXTRACHILL_LINK_PAGES_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
// Legacy storage slug. Existing records remain on their current blog unchanged.
defined( 'EC_LINK_PAGE_POST_TYPE' ) || define( 'EC_LINK_PAGE_POST_TYPE', 'artist_link_page' );
defined( 'EC_LINK_PAGE_OWNER_META_KEY' ) || define( 'EC_LINK_PAGE_OWNER_META_KEY', '_ec_link_page_owner_reference' );

require_once EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'inc/migration.php';

/**
 * Return the exact public function signatures in runtime API version 1.
 *
 * @return array<string,array{required:int,total:int}>
 */
function ec_link_pages_runtime_function_contract() {
	$contract = array(
		'ec_link_page_migration_participant_registry'      => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_register_link_page_migration_participant'      => array(
			'required' => 3,
			'total'    => 4,
		),
		'ec_link_page_owner_compatibility_registry'        => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_register_link_page_owner_compatibility_provider' => array(
			'required' => 2,
			'total'    => 3,
		),
		'ec_can_register_link_page_owner_compatibility_provider' => array(
			'required' => 2,
			'total'    => 3,
		),
		'ec_parse_link_page_owner_reference'               => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_format_link_page_owner_reference'              => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_normalize_link_page_owner_reference'           => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_get_stored_link_page_owner_references'         => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_validate_link_page_owner_compatibility_claim'  => array(
			'required' => 3,
			'total'    => 3,
		),
		'ec_restore_link_page_owner_provider_context'      => array(
			'required' => 3,
			'total'    => 3,
		),
		'ec_invoke_link_page_owner_compatibility_provider' => array(
			'required' => 3,
			'total'    => 3,
		),
		'ec_collect_raw_link_page_owner_compatibility_claims' => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_reconcile_link_page_owner_candidate'           => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_collect_link_page_owner_compatibility_claims'  => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_get_link_page_owner'                           => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_get_link_page_id_for_owner'                    => array(
			'required' => 1,
			'total'    => 2,
		),
		'ec_validate_link_page_owner_candidate_ids'        => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_assign_link_page_owner'                        => array(
			'required' => 2,
			'total'    => 3,
		),
		'ec_compensate_link_page_owner_assignment'         => array(
			'required' => 4,
			'total'    => 4,
		),
		'ec_halt_link_page_owner_backfill'                 => array(
			'required' => 4,
			'total'    => 4,
		),
		'ec_backfill_link_page_owner_references'           => array(
			'required' => 0,
			'total'    => 2,
		),
		'ec_link_page_operation_provider_registry'         => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_register_link_page_operation_provider'         => array(
			'required' => 2,
			'total'    => 3,
		),
		'ec_can_register_link_page_operation_provider'     => array(
			'required' => 2,
			'total'    => 3,
		),
		'ec_resolve_link_page_operation_target'            => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_invoke_link_page_operation_callback'           => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_get_link_page_operation_provider'              => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_prepare_link_page_operation'                   => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_read_link_page'                                => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_save_link_page'                                => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_link_page_defaults'                            => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_link_page_defaults_for'                        => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_link_page_default'                             => array(
			'required' => 2,
			'total'    => 3,
		),
		'ec_sanitize_link_page_links'                      => array(
			'required' => 1,
			'total'    => 2,
		),
		'ec_sanitize_link_page_css_vars'                   => array(
			'required' => 1,
			'total'    => 2,
		),
		'ec_sanitize_link_page_settings'                   => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_read_link_page_persistence'                    => array(
			'required' => 1,
			'total'    => 2,
		),
		'ec_save_link_page_persistence'                    => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_save_link_page_persistence_composed'           => array(
			'required' => 3,
			'total'    => 3,
		),
		'ec_create_owned_link_page'                        => array(
			'required' => 3,
			'total'    => 4,
		),
		'ec_provision_owned_link_page'                     => array(
			'required' => 3,
			'total'    => 5,
		),
		'ec_provision_owned_link_page_composed'            => array(
			'required' => 4,
			'total'    => 6,
		),
		'ec_provision_owned_link_page_internal'            => array(
			'required' => 6,
			'total'    => 6,
		),
		'ec_invoke_link_page_provision_precondition'       => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_create_owned_link_page_unlocked'               => array(
			'required' => 3,
			'total'    => 4,
		),
		'ec_link_page_public_projection_registry'          => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_register_link_page_public_projection_provider' => array(
			'required' => 2,
			'total'    => 3,
		),
		'ec_can_register_link_page_public_projection_provider' => array(
			'required' => 2,
			'total'    => 3,
		),
		'ec_sanitize_link_page_public_projection_snapshot' => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_save_link_page_public_projection_snapshot'     => array(
			'required' => 3,
			'total'    => 3,
		),
		'ec_read_link_page_public_projection_snapshot'     => array(
			'required' => 1,
			'total'    => 2,
		),
		'ec_render_stored_link_page_social_links'          => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_get_link_page_public_projection'               => array(
			'required' => 1,
			'total'    => 2,
		),
		'ec_render_link_page_public_components'            => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_get_link_page_public_url'                      => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_link_page_public_urls'                         => array(
			'required' => 1,
			'total'    => 1,
		),
	);
	return $contract + array(
		'ec_get_link_page_storage_blog_id'               => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_with_link_page_storage_blog'                 => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_register_link_page_post_type'                => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_register_link_page_post_type_if_ready'       => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_restore_link_pages_site_context'             => array(
			'required' => 3,
			'total'    => 3,
		),
		'ec_invoke_link_pages_site_callback'             => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_for_each_link_pages_site'                    => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_flush_link_pages_site'                       => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_prepare_link_pages_activation'               => array(
			'required' => 0,
			'total'    => 1,
		),
		'ec_activate_link_pages'                         => array(
			'required' => 0,
			'total'    => 1,
		),
		'ec_deactivate_link_pages'                       => array(
			'required' => 0,
			'total'    => 1,
		),
		'ec_unregister_and_flush_link_pages_site'        => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_link_pages_is_network_active'                => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_initialize_link_pages_site'                  => array(
			'required' => 1,
			'total'    => 2,
		),
		'ec_flush_queued_link_pages_sites'               => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_register_link_page_public_compatibility_aliases' => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_link_page_id_meta_keys'                      => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_link_page_needs_id_assignment'               => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_with_link_page_id_lock'                      => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_with_link_page_lock_scope'                   => array(
			'required' => 2,
			'total'    => 3,
		),
		'ec_link_page_next_element_id'                   => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_link_page_sync_element_counter'              => array(
			'required' => 3,
			'total'    => 3,
		),
		'ec_collect_link_page_element_ids'               => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_sanitize_link_page_links_locked'             => array(
			'required' => 2,
			'total'    => 3,
		),
		'ec_snapshot_link_page_meta'                     => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_write_link_page_meta'                        => array(
			'required' => 3,
			'total'    => 4,
		),
		'ec_restore_link_page_meta_snapshots'            => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_compensate_link_page_save_error'             => array(
			'required' => 3,
			'total'    => 3,
		),
		'ec_save_link_page_persistence_locked'           => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_save_link_page_persistence_composed_locked'  => array(
			'required' => 3,
			'total'    => 3,
		),
		'ec_invoke_link_page_mutation_finalizer'         => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_purge_link_page_after_mutation'              => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_compensate_created_link_page'                => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_restore_replaced_link_page'                  => array(
			'required' => 3,
			'total'    => 3,
		),
		'ec_compensate_link_page_creation_error'         => array(
			'required' => 4,
			'total'    => 4,
		),
		'ec_prepare_owned_link_page_creation'            => array(
			'required' => 3,
			'total'    => 4,
		),
		'ec_cleanup_expired_link_page_links'             => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_purge_link_page_before_delete'               => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_schedule_link_page_expiration_cleanup'       => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_unschedule_link_page_expiration_cleanup'     => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_invoke_link_page_public_projection_callback' => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_validate_link_page_public_projection'        => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_prepare_link_page_public_render'             => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_is_link_page_public_host'                    => array(
			'required' => 0,
			'total'    => 1,
		),
		'ec_get_link_page_public_query_var'              => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_get_link_page_public_query_vars'             => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_get_link_page_public_exclusions'             => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_link_page_cache_post_change_urls'            => array(
			'required' => 3,
			'total'    => 3,
		),
		'ec_register_link_page_public_rewrites'          => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_add_link_page_public_query_vars'             => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_terminate_link_page_request'                 => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_maybe_flush_link_page_public_rewrites'       => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_prevent_link_page_public_canonical_redirect' => array(
			'required' => 2,
			'total'    => 2,
		),
		'ec_link_page_public_redirect'                   => array(
			'required' => 1,
			'total'    => 3,
		),
		'ec_resolve_link_page_public_query'              => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_link_page_public_template'                   => array(
			'required' => 1,
			'total'    => 1,
		),
		'ec_redirect_direct_link_page_request'           => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_enqueue_link_page_minimal_assets'            => array(
			'required' => 1,
			'total'    => 2,
		),
		'ec_link_page_css_variables_style_block'         => array(
			'required' => 1,
			'total'    => 2,
		),
		'ec_render_link_page_section'                    => array(
			'required' => 3,
			'total'    => 3,
		),
		'ec_render_link_page_public_head'                => array(
			'required' => 3,
			'total'    => 3,
		),
		'ec_link_page_sitemap_urls'                      => array(
			'required' => 1,
			'total'    => 1,
		),
	);
}

/**
 * Record the first runtime failure for operators and integrations.
 *
 * @param WP_Error $error Runtime failure.
 * @return WP_Error
 */
function ec_record_link_pages_runtime_error( $error ) {
	if ( ! isset( $GLOBALS['ec_link_pages_runtime_error'] ) ) {
		$GLOBALS['ec_link_pages_runtime_error'] = $error;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Boot failures need an operator-visible server log before admin hooks run.
		error_log( 'Extra Chill Link Pages: ' . $error->get_error_message() );
		do_action( 'ec_link_pages_runtime_error', $error );
	}
	return $error;
}

/**
 * Validate one independently loadable runtime component.
 *
 * @param array<string,array{required:int,total:int}> $contract Component contract.
 * @param string                                      $file     Component file when wholly absent.
 * @return true|WP_Error
 */
function ec_load_link_pages_runtime_component( $contract, $file ) {
	$loaded = array_filter( array_keys( $contract ), 'function_exists' );
	if ( empty( $loaded ) ) {
		try {
			require_once $file;
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'ec_link_pages_runtime_load_failed', 'The Link Pages runtime component could not be loaded.' );
		}
	} elseif ( count( $loaded ) !== count( $contract ) ) {
		return new WP_Error( 'ec_link_pages_runtime_partial', 'A partial Link Pages runtime component was already loaded.' );
	}

	foreach ( $contract as $function => $signature ) {
		if ( ! function_exists( $function ) ) {
			return new WP_Error( 'ec_link_pages_runtime_incomplete', 'The Link Pages runtime did not load its complete generic API.' );
		}
		try {
			$reflection = new ReflectionFunction( $function );
		} catch ( ReflectionException $exception ) {
			return new WP_Error( 'ec_link_pages_runtime_incompatible', 'The Link Pages runtime function contract could not be inspected.' );
		}
		if ( $reflection->getNumberOfRequiredParameters() !== $signature['required'] || $reflection->getNumberOfParameters() !== $signature['total'] ) {
			return new WP_Error( 'ec_link_pages_runtime_incompatible', 'The Link Pages runtime uses an incompatible function contract.' );
		}
	}

	return true;
}

$ec_link_pages_contract               = ec_link_pages_runtime_function_contract();
$ec_link_pages_subset                 = static function ( $names ) use ( $ec_link_pages_contract ) {
	return array_intersect_key( $ec_link_pages_contract, array_flip( $names ) );
};
$ec_link_pages_owner_contract         = $ec_link_pages_subset( array( 'ec_link_page_owner_compatibility_registry', 'ec_register_link_page_owner_compatibility_provider', 'ec_parse_link_page_owner_reference', 'ec_format_link_page_owner_reference', 'ec_normalize_link_page_owner_reference', 'ec_get_stored_link_page_owner_references', 'ec_validate_link_page_owner_compatibility_claim', 'ec_restore_link_page_owner_provider_context', 'ec_invoke_link_page_owner_compatibility_provider', 'ec_collect_raw_link_page_owner_compatibility_claims', 'ec_reconcile_link_page_owner_candidate', 'ec_collect_link_page_owner_compatibility_claims', 'ec_get_link_page_owner', 'ec_get_link_page_id_for_owner', 'ec_validate_link_page_owner_candidate_ids', 'ec_assign_link_page_owner', 'ec_compensate_link_page_owner_assignment', 'ec_halt_link_page_owner_backfill', 'ec_backfill_link_page_owner_references' ) );
$ec_link_pages_lifecycle_contract     = $ec_link_pages_subset( array( 'ec_get_link_page_storage_blog_id', 'ec_with_link_page_storage_blog', 'ec_register_link_page_post_type', 'ec_register_link_page_post_type_if_ready', 'ec_restore_link_pages_site_context', 'ec_invoke_link_pages_site_callback', 'ec_for_each_link_pages_site', 'ec_flush_link_pages_site', 'ec_prepare_link_pages_activation', 'ec_activate_link_pages', 'ec_deactivate_link_pages', 'ec_unregister_and_flush_link_pages_site', 'ec_link_pages_is_network_active', 'ec_initialize_link_pages_site', 'ec_flush_queued_link_pages_sites' ) );
$ec_link_pages_compatibility_contract = $ec_link_pages_subset( array( 'ec_register_link_page_public_compatibility_aliases' ) );
$ec_link_pages_operation_contract     = $ec_link_pages_subset( array( 'ec_link_page_operation_provider_registry', 'ec_register_link_page_operation_provider', 'ec_resolve_link_page_operation_target', 'ec_invoke_link_page_operation_callback', 'ec_get_link_page_operation_provider', 'ec_prepare_link_page_operation', 'ec_read_link_page', 'ec_save_link_page' ) );
$ec_link_pages_storage_contract       = $ec_link_pages_subset( array( 'ec_link_page_defaults', 'ec_link_page_defaults_for', 'ec_link_page_default', 'ec_link_page_id_meta_keys', 'ec_link_page_needs_id_assignment', 'ec_with_link_page_lock_scope', 'ec_with_link_page_id_lock', 'ec_link_page_next_element_id', 'ec_link_page_sync_element_counter', 'ec_sanitize_link_page_links', 'ec_collect_link_page_element_ids', 'ec_sanitize_link_page_links_locked', 'ec_sanitize_link_page_css_vars', 'ec_sanitize_link_page_settings', 'ec_read_link_page_persistence', 'ec_snapshot_link_page_meta', 'ec_write_link_page_meta', 'ec_restore_link_page_meta_snapshots', 'ec_compensate_link_page_save_error', 'ec_purge_link_page_after_mutation', 'ec_save_link_page_persistence', 'ec_save_link_page_persistence_composed', 'ec_save_link_page_persistence_locked', 'ec_save_link_page_persistence_composed_locked', 'ec_invoke_link_page_mutation_finalizer', 'ec_compensate_created_link_page', 'ec_restore_replaced_link_page', 'ec_compensate_link_page_creation_error', 'ec_provision_owned_link_page', 'ec_provision_owned_link_page_composed', 'ec_provision_owned_link_page_internal', 'ec_invoke_link_page_provision_precondition', 'ec_create_owned_link_page', 'ec_create_owned_link_page_unlocked', 'ec_prepare_owned_link_page_creation', 'ec_cleanup_expired_link_page_links', 'ec_purge_link_page_before_delete', 'ec_schedule_link_page_expiration_cleanup', 'ec_unschedule_link_page_expiration_cleanup' ) );
$ec_link_pages_projection_contract    = $ec_link_pages_subset( array( 'ec_link_page_public_projection_registry', 'ec_register_link_page_public_projection_provider', 'ec_can_register_link_page_public_projection_provider', 'ec_invoke_link_page_public_projection_callback', 'ec_validate_link_page_public_projection', 'ec_sanitize_link_page_public_projection_snapshot', 'ec_save_link_page_public_projection_snapshot', 'ec_read_link_page_public_projection_snapshot', 'ec_render_stored_link_page_social_links', 'ec_get_link_page_public_projection', 'ec_render_link_page_public_components', 'ec_prepare_link_page_public_render' ) );
$ec_link_pages_public_contract        = $ec_link_pages_subset( array( 'ec_is_link_page_public_host', 'ec_get_link_page_public_url', 'ec_get_link_page_public_query_var', 'ec_get_link_page_public_query_vars', 'ec_get_link_page_public_exclusions', 'ec_link_page_public_urls', 'ec_link_page_cache_post_change_urls', 'ec_register_link_page_public_rewrites', 'ec_add_link_page_public_query_vars', 'ec_terminate_link_page_request', 'ec_maybe_flush_link_page_public_rewrites', 'ec_prevent_link_page_public_canonical_redirect', 'ec_link_page_public_redirect', 'ec_resolve_link_page_public_query', 'ec_link_page_public_template', 'ec_redirect_direct_link_page_request', 'ec_enqueue_link_page_minimal_assets', 'ec_link_page_css_variables_style_block', 'ec_render_link_page_section', 'ec_render_link_page_public_head', 'ec_link_page_sitemap_urls' ) );

$ec_link_pages_lifecycle_result = ec_load_link_pages_runtime_component( $ec_link_pages_lifecycle_contract, EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'inc/post-type.php' );
if ( is_wp_error( $ec_link_pages_lifecycle_result ) ) {
	ec_record_link_pages_runtime_error( $ec_link_pages_lifecycle_result );
}
$ec_link_pages_compatibility_result = ec_load_link_pages_runtime_component( $ec_link_pages_compatibility_contract, EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'inc/compatibility.php' );
if ( is_wp_error( $ec_link_pages_compatibility_result ) ) {
	ec_record_link_pages_runtime_error( $ec_link_pages_compatibility_result );
}
$ec_link_pages_owner_result = ec_load_link_pages_runtime_component( $ec_link_pages_owner_contract, EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'inc/owner-reference.php' );
if ( is_wp_error( $ec_link_pages_owner_result ) ) {
	ec_record_link_pages_runtime_error( $ec_link_pages_owner_result );
}
$ec_link_pages_operation_result = ec_load_link_pages_runtime_component( $ec_link_pages_operation_contract, EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'inc/operations.php' );
if ( is_wp_error( $ec_link_pages_operation_result ) ) {
	ec_record_link_pages_runtime_error( $ec_link_pages_operation_result );
}
$ec_link_pages_storage_result = ec_load_link_pages_runtime_component( $ec_link_pages_storage_contract, EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'inc/storage.php' );
if ( is_wp_error( $ec_link_pages_storage_result ) ) {
	ec_record_link_pages_runtime_error( $ec_link_pages_storage_result );
}
$ec_link_pages_public_result = ec_load_link_pages_runtime_component( $ec_link_pages_public_contract, EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'inc/public-runtime.php' );
if ( is_wp_error( $ec_link_pages_public_result ) ) {
	ec_record_link_pages_runtime_error( $ec_link_pages_public_result );
}
$ec_link_pages_projection_result = ec_load_link_pages_runtime_component( $ec_link_pages_projection_contract, EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'inc/public-projections.php' );
if ( is_wp_error( $ec_link_pages_projection_result ) ) {
	ec_record_link_pages_runtime_error( $ec_link_pages_projection_result );
}

if ( ! function_exists( 'ec_can_register_link_page_owner_compatibility_provider' ) ) {
	/** Preflight against a rolling fallback owner registry. */
	function ec_can_register_link_page_owner_compatibility_provider( $name, $callback, $priority = 10 ) {
		if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $name ) || ! is_callable( $callback ) || ! is_int( $priority ) ) {
			return new WP_Error( 'invalid_link_page_owner_provider', 'The Link Page owner compatibility provider registration is invalid.' );
		}
		return in_array( $name, array_column( ec_link_page_owner_compatibility_registry()->snapshot(), 'name' ), true ) ? new WP_Error( 'duplicate_link_page_owner_provider', 'The Link Page owner compatibility provider is already registered.' ) : true;
	}
}
if ( ! function_exists( 'ec_can_register_link_page_operation_provider' ) ) {
	/** Preflight against a rolling fallback operation registry. */
	function ec_can_register_link_page_operation_provider( $name, $callback, $priority = 10 ) {
		if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $name ) || ! is_callable( $callback ) || ! is_int( $priority ) ) {
			return new WP_Error( 'invalid_link_page_operation_provider', 'The Link Page operation provider registration is invalid.' );
		}
		return in_array( $name, array_column( ec_link_page_operation_provider_registry()->snapshot(), 'name' ), true ) ? new WP_Error( 'duplicate_link_page_operation_provider', 'The Link Page operation provider is already registered.' ) : true;
	}
}

if ( ! defined( 'EC_LINK_PAGES_RUNTIME_API_VERSION' ) ) {
	define( 'EC_LINK_PAGES_RUNTIME_API_VERSION', '3' );
}

/**
 * Validate the complete standalone runtime contract.
 *
 * @param bool $check_readiness Whether to invoke the public readiness callback.
 * @return true|WP_Error
 */
function ec_validate_link_pages_runtime( $check_readiness = true ) {
	if ( isset( $GLOBALS['ec_link_pages_runtime_error'] ) && is_wp_error( $GLOBALS['ec_link_pages_runtime_error'] ) ) {
		return $GLOBALS['ec_link_pages_runtime_error'];
	}
	if ( 'artist_link_page' !== EC_LINK_PAGE_POST_TYPE || '_ec_link_page_owner_reference' !== EC_LINK_PAGE_OWNER_META_KEY ) {
		return new WP_Error( 'ec_link_pages_runtime_incompatible', 'The Link Pages runtime uses an incompatible storage contract.' );
	}
	if ( '3' !== EC_LINK_PAGES_RUNTIME_API_VERSION ) {
		return new WP_Error( 'ec_link_pages_runtime_incompatible', 'The Link Pages runtime API version is not supported.' );
	}
	foreach ( ec_link_pages_runtime_function_contract() as $function => $signature ) {
		if ( ! function_exists( $function ) ) {
			return new WP_Error( 'ec_link_pages_runtime_incomplete', 'The Link Pages runtime did not load its complete generic API.' );
		}
		$reflection = new ReflectionFunction( $function );
		if ( $reflection->getNumberOfRequiredParameters() !== $signature['required'] || $reflection->getNumberOfParameters() !== $signature['total'] ) {
			return new WP_Error( 'ec_link_pages_runtime_incompatible', 'The Link Pages runtime uses an incompatible function contract.' );
		}
	}
	if ( $check_readiness && function_exists( 'ec_link_pages_runtime_ready' ) ) {
		try {
			$readiness = new ReflectionFunction( 'ec_link_pages_runtime_ready' );
			if ( 0 !== $readiness->getNumberOfParameters() ) {
				return new WP_Error( 'ec_link_pages_runtime_incompatible', 'The Link Pages runtime uses an incompatible readiness contract.' );
			}
			$ready = ec_link_pages_runtime_ready();
		} catch ( Throwable $throwable ) {
			return new WP_Error( 'ec_link_pages_runtime_readiness_failed', 'The Link Pages runtime readiness check failed.' );
		}
		if ( true !== $ready ) {
			return new WP_Error( 'ec_link_pages_runtime_incomplete', 'The Link Pages runtime reported that it is not ready.' );
		}
	}
	return true;
}

if ( ! function_exists( 'ec_link_pages_runtime_ready' ) ) {
	/**
	 * Report whether the complete public runtime contract is available.
	 *
	 * @return bool
	 */
	function ec_link_pages_runtime_ready() {
		return true === ec_validate_link_pages_runtime( false );
	}
}

$ec_link_pages_validation = ec_validate_link_pages_runtime();
if ( is_wp_error( $ec_link_pages_validation ) ) {
	ec_record_link_pages_runtime_error( $ec_link_pages_validation );
}

/** Display the stored runtime failure to administrators. */
function ec_link_pages_runtime_error_notice() {
	$error = $GLOBALS['ec_link_pages_runtime_error'] ?? null;
	if ( is_wp_error( $error ) ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error->get_error_message() ) );
	}
}

/** Register the portable editor block and public asset handle. */
function ec_register_link_page_editor() {
	$block = EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'build/editor';
	if ( ! file_exists( $block . '/block.json' ) || ! file_exists( $block . '/index.asset.php' ) ) {
		return;
	}
	if ( class_exists( 'WP_Block_Type_Registry' ) && WP_Block_Type_Registry::get_instance()->is_registered( 'extrachill/link-page-editor' ) ) {
		return;
	}
	register_block_type( $block );
}

/** Whether consumers can safely embed the portable editor. */
function ec_link_page_editor_is_available() {
	return wp_script_is( 'extrachill-link-page-editor-view-script', 'registered' );
}

/** Enqueue the portable editor for an owning-platform mount point. */
function ec_enqueue_link_page_editor() {
	if ( ! ec_link_page_editor_is_available() ) {
		return false;
	}
	wp_enqueue_script( 'extrachill-link-page-editor-view-script' );
	wp_enqueue_style( 'extrachill-link-page-editor-style' );
	foreach ( array(
		'extrch-link-page'           => 'assets/css/extrch-links.css',
		'extrch-share-modal'         => 'assets/css/extrch-share-modal.css',
		'extrch-custom-social-icons' => 'assets/css/custom-social-icons.css',
	) as $handle => $path ) {
		$file = EXTRACHILL_LINK_PAGES_PLUGIN_DIR . $path;
		wp_enqueue_style( $handle, plugins_url( $path, EXTRACHILL_LINK_PAGES_PLUGIN_FILE ), array(), file_exists( $file ) ? filemtime( $file ) : EXTRACHILL_LINK_PAGES_VERSION );
	}
	return true;
}

add_action( 'init', 'ec_register_link_page_post_type_if_ready', 5 );
add_action( 'init', 'ec_register_link_page_editor', 20 );
add_action( 'wp_initialize_site', 'ec_initialize_link_pages_site', 200, 2 );
add_action( 'wp_loaded', 'ec_flush_queued_link_pages_sites', 200 );
add_action( 'admin_notices', 'ec_link_pages_runtime_error_notice' );
add_action( 'network_admin_notices', 'ec_link_pages_runtime_error_notice' );
register_activation_hook( __FILE__, 'ec_activate_link_pages' );
register_deactivation_hook( __FILE__, 'ec_deactivate_link_pages' );
