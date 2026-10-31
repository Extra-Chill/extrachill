<?php
/**
 * Production ad-delivery integration health evidence.
 *
 * @package ExtraChill\Network
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return MCP options that directly affect anonymous frontend markup.
 *
 * Defaults match MCP's frontend getters so adding an explicit default value
 * does not cause a cache purge.
 *
 * @return array<string, bool|string>
 */
function extrachill_mediavine_frontend_option_defaults(): array {
	return array(
		'mcp_include_script_wrapper' => true,
		'mcp_site_id'                => '',
		'mcp_launch_mode'            => true,
		'mcp_offering_domain'        => 'mediavine.com',
		'mcp_offering_code'          => 'mediavine',
		'mcp_enable_gpt_snippet'     => false,
		'mcp_mcm_code'               => '',
		'mcp_mcm_approval'           => false,
		'mcp_google'                 => false,
		'mcp_enable_web_story_ads'   => true,
		'mcp_adunit_name'            => '',
	);
}

/**
 * Normalize an MCP option using the same effective type as its frontend read.
 *
 * @param string $option Option name.
 * @param mixed  $value  Stored option value.
 * @return bool|string
 */
function extrachill_mediavine_normalize_frontend_option( string $option, $value ) {
	$defaults = extrachill_mediavine_frontend_option_defaults();

	if ( is_bool( $defaults[ $option ] ) ) {
		return ! empty( $value );
	}

	return (string) $value;
}

/**
 * Flush the current site's page cache when it is active.
 *
 * @return void
 */
function extrachill_mediavine_flush_current_site_cache(): void {
	if ( has_action( 'extrachill_cache_flush' ) ) {
		do_action( 'extrachill_cache_flush' );
	}
}

/**
 * Purge after an existing frontend-affecting MCP option effectively changes.
 *
 * @param mixed  $old_value Previous option value.
 * @param mixed  $new_value New option value.
 * @param string $option    Option name.
 * @return void
 */
function extrachill_mediavine_purge_on_frontend_option_update( $old_value, $new_value, string $option ): void {
	if ( extrachill_mediavine_normalize_frontend_option( $option, $old_value ) === extrachill_mediavine_normalize_frontend_option( $option, $new_value ) ) {
		return;
	}

	extrachill_mediavine_flush_current_site_cache();
}

/**
 * Purge when adding an MCP option changes its effective frontend default.
 *
 * @param string $option Option name.
 * @param mixed  $value  Added option value.
 * @return void
 */
function extrachill_mediavine_purge_on_frontend_option_add( string $option, $value ): void {
	$defaults = extrachill_mediavine_frontend_option_defaults();

	if ( extrachill_mediavine_normalize_frontend_option( $option, $defaults[ $option ] ) === extrachill_mediavine_normalize_frontend_option( $option, $value ) ) {
		return;
	}

	extrachill_mediavine_flush_current_site_cache();
}

foreach ( array_keys( extrachill_mediavine_frontend_option_defaults() ) as $extrachill_mediavine_option ) {
	add_action( "update_option_{$extrachill_mediavine_option}", 'extrachill_mediavine_purge_on_frontend_option_update', 10, 3 );
	add_action( "add_option_{$extrachill_mediavine_option}", 'extrachill_mediavine_purge_on_frontend_option_add', 10, 2 );
}

/**
 * Purge when per-site plugin activation changes wrapper availability.
 *
 * Network-wide activation changes more than the current site and is therefore
 * outside this site-scoped adapter. MCP is activated per site on the ad sites.
 *
 * @param string $plugin       Activated or deactivated plugin basename.
 * @param bool   $network_wide Whether the operation affects the full network.
 * @return void
 */
function extrachill_mediavine_purge_on_plugin_status_change( string $plugin, bool $network_wide ): void {
	if ( $network_wide || 'mediavine-control-panel/mediavine-control-panel.php' !== $plugin ) {
		return;
	}

	extrachill_mediavine_flush_current_site_cache();
}
add_action( 'activated_plugin', 'extrachill_mediavine_purge_on_plugin_status_change', 10, 2 );
add_action( 'deactivated_plugin', 'extrachill_mediavine_purge_on_plugin_status_change', 10, 2 );

/**
 * Report whether the configured delivery plugin is active for a site.
 *
 * This adapter is intentionally separate from the vendor-neutral policy.
 * Plugin state diagnoses operational drift; it never determines site intent.
 *
 * @param array<string, bool> $health  Existing health evidence.
 * @param int                 $blog_id Site ID.
 * @return array<string, bool>
 */
function extrachill_mediavine_ad_integration_health( array $health, int $blog_id ): array {
	$plugin_file     = 'mediavine-control-panel/mediavine-control-panel.php';
	$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
	$site_plugins    = (array) get_blog_option( $blog_id, 'active_plugins', array() );
	$is_active       = isset( $network_plugins[ $plugin_file ] ) || in_array( $plugin_file, $site_plugins, true );

	$health['available']         = $is_active;
	$health['delivery_detected'] = $is_active;

	return $health;
}
add_filter( 'extrachill_ad_integration_health', 'extrachill_mediavine_ad_integration_health', 10, 2 );
