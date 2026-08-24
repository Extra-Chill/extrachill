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

require_once EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'inc/post-type.php';

/**
 * Return the exact public function signatures in runtime API version 1.
 *
 * @return array<string,array{required:int,total:int}>
 */
function ec_link_pages_runtime_function_contract() {
	return array(
		'ec_link_page_owner_compatibility_registry'        => array(
			'required' => 0,
			'total'    => 0,
		),
		'ec_register_link_page_owner_compatibility_provider' => array(
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

$ec_link_pages_contract           = ec_link_pages_runtime_function_contract();
$ec_link_pages_owner_contract     = array_slice( $ec_link_pages_contract, 0, 19, true );
$ec_link_pages_operation_contract = array_slice( $ec_link_pages_contract, 19, null, true );

$ec_link_pages_owner_result = ec_load_link_pages_runtime_component( $ec_link_pages_owner_contract, EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'inc/owner-reference.php' );
if ( is_wp_error( $ec_link_pages_owner_result ) ) {
	ec_record_link_pages_runtime_error( $ec_link_pages_owner_result );
}
$ec_link_pages_operation_result = ec_load_link_pages_runtime_component( $ec_link_pages_operation_contract, EXTRACHILL_LINK_PAGES_PLUGIN_DIR . 'inc/operations.php' );
if ( is_wp_error( $ec_link_pages_operation_result ) ) {
	ec_record_link_pages_runtime_error( $ec_link_pages_operation_result );
}

if ( ! defined( 'EC_LINK_PAGES_RUNTIME_API_VERSION' ) ) {
	define( 'EC_LINK_PAGES_RUNTIME_API_VERSION', '1' );
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
	if ( '1' !== EC_LINK_PAGES_RUNTIME_API_VERSION ) {
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

add_action( 'init', 'ec_register_link_page_post_type_if_ready', 5 );
add_action( 'wp_initialize_site', 'ec_initialize_link_pages_site', 200, 2 );
add_action( 'wp_loaded', 'ec_flush_queued_link_pages_sites', 200 );
add_action( 'admin_notices', 'ec_link_pages_runtime_error_notice' );
add_action( 'network_admin_notices', 'ec_link_pages_runtime_error_notice' );
register_activation_hook( __FILE__, 'ec_activate_link_pages' );
register_deactivation_hook( __FILE__, 'ec_deactivate_link_pages' );
