<?php
/**
 * Standalone tests for Mediavine-triggered current-site cache purges.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['mediavine_actions']         = array();
$GLOBALS['mediavine_current_blog_id'] = 1;
$GLOBALS['mediavine_purged_blogs']    = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['mediavine_actions'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	add_action( $hook, $callback, $priority, $accepted_args );
}

function has_action( $hook ) {
	return ! empty( $GLOBALS['mediavine_actions'][ $hook ] );
}

function do_action( $hook, ...$args ) {
	if ( empty( $GLOBALS['mediavine_actions'][ $hook ] ) ) {
		return;
	}

	ksort( $GLOBALS['mediavine_actions'][ $hook ] );
	foreach ( $GLOBALS['mediavine_actions'][ $hook ] as $callbacks ) {
		foreach ( $callbacks as list( $callback, $accepted_args ) ) {
			$callback( ...array_slice( $args, 0, $accepted_args ) );
		}
	}
}

function get_current_blog_id() {
	return $GLOBALS['mediavine_current_blog_id'];
}

function get_site_option( $name, $default = false ) {
	return $default;
}

function get_blog_option( $blog_id, $name, $default = false ) {
	return $default;
}

function mediavine_assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function mediavine_run_action( string $hook, ...$args ): void {
	do_action( $hook, ...$args );
}

require_once dirname( __DIR__ ) . '/inc/integrations/ad-delivery.php';

$expected_options = array(
	'mcp_include_script_wrapper',
	'mcp_site_id',
	'mcp_launch_mode',
	'mcp_offering_domain',
	'mcp_offering_code',
	'mcp_enable_gpt_snippet',
	'mcp_mcm_code',
	'mcp_mcm_approval',
	'mcp_google',
	'mcp_enable_web_story_ads',
	'mcp_adunit_name',
);
mediavine_assert_same( $expected_options, array_keys( extrachill_mediavine_frontend_option_defaults() ), 'Only verified anonymous frontend options are registered.' );

foreach ( $expected_options as $option ) {
	mediavine_assert_same( true, has_action( "update_option_{$option}" ), "Update hook is registered for {$option}." );
	mediavine_assert_same( true, has_action( "add_option_{$option}" ), "Add hook is registered for {$option}." );
}
mediavine_assert_same( false, has_action( 'update_option_mcp_version' ), 'Unrelated MCP state does not register a purge hook.' );
mediavine_assert_same( false, has_action( 'update_option_mcp_txt_redirections_check_in_progress' ), 'MCP cron state does not register a purge hook.' );

add_action(
	'extrachill_cache_flush',
	static function (): void {
		$GLOBALS['mediavine_purged_blogs'][] = get_current_blog_id();
	}
);

mediavine_run_action( 'update_option_mcp_include_script_wrapper', '1', true, 'mcp_include_script_wrapper' );
mediavine_run_action( 'update_option_mcp_launch_mode', 1, 'yes', 'mcp_launch_mode' );
mediavine_run_action( 'add_option_mcp_offering_domain', 'mcp_offering_domain', 'mediavine.com' );
mediavine_assert_same( array(), $GLOBALS['mediavine_purged_blogs'], 'Semantically unchanged updates and explicit defaults do not purge.' );

foreach ( array( 1, 7, 11 ) as $blog_id ) {
	$GLOBALS['mediavine_current_blog_id'] = $blog_id;
	mediavine_run_action( 'update_option_mcp_site_id', "site-{$blog_id}-old", "site-{$blog_id}-new", 'mcp_site_id' );
}
mediavine_assert_same( array( 1, 7, 11 ), $GLOBALS['mediavine_purged_blogs'], 'Blog, Events, and Wire changes purge only their current cache partitions.' );

$GLOBALS['mediavine_current_blog_id'] = 7;
mediavine_run_action( 'updated_option', 'mcp_version', '2.10.8', '2.10.9' );
mediavine_assert_same( array( 1, 7, 11 ), $GLOBALS['mediavine_purged_blogs'], 'Unrelated option updates do not purge.' );

mediavine_run_action( 'activated_plugin', 'mediavine-control-panel/mediavine-control-panel.php', false );
mediavine_run_action( 'deactivated_plugin', 'mediavine-control-panel/mediavine-control-panel.php', false );
mediavine_run_action( 'activated_plugin', 'mediavine-control-panel/mediavine-control-panel.php', true );
mediavine_run_action( 'deactivated_plugin', 'other-plugin/other-plugin.php', false );
mediavine_assert_same( array( 1, 7, 11, 7, 7 ), $GLOBALS['mediavine_purged_blogs'], 'Only per-site MCP activation and deactivation purge.' );

unset( $GLOBALS['mediavine_actions']['extrachill_cache_flush'] );
$GLOBALS['mediavine_current_blog_id'] = 11;
mediavine_run_action( 'update_option_mcp_offering_domain', 'mediavine.com', 'journeymv.com', 'mcp_offering_domain' );
mediavine_assert_same( array( 1, 7, 11, 7, 7 ), $GLOBALS['mediavine_purged_blogs'], 'Inactive cache integration is a safe no-op.' );

fwrite( STDOUT, "Mediavine cache purge tests passed.\n" );
