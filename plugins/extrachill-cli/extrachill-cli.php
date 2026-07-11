<?php
/**
 * Plugin Name: Extra Chill CLI
 * Plugin URI: https://extrachill.com
 * Description: WP-CLI command surface for the Extra Chill platform. Wraps abilities from feature plugins into a unified `wp extrachill` namespace.
 * Version: 0.28.0
 * Author: Extra Chill
 * Author URI: https://extrachill.com
 * Network: true
 * Text Domain: extrachill-cli
 *
 * @package ExtraChill\CLI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_CLI_VERSION', '0.28.0' );
define( 'EXTRACHILL_CLI_PATH', plugin_dir_path( __FILE__ ) );

/*
|--------------------------------------------------------------------------
| AGENTS.md — composable file section registration
|--------------------------------------------------------------------------
| Registers the Extra Chill CLI section in the AGENTS.md composable file
| so that external agent runtimes (Claude Code, OpenCode, etc.) discover
| the platform CLI surface automatically. Runs outside the WP_CLI guard
| because the compose command and auto-regeneration may fire in non-CLI
| WordPress contexts (e.g. plugin activation hooks).
*/
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( '\DataMachine\Engine\AI\SectionRegistry' ) ) {
		return;
	}

	// This section can be composed in non-CLI (web/cron) contexts where the
	// WP_CLI guard below has not registered the autoloader. Register it here
	// so the generator can reflect over the real command classes (and any
	// traits they use) regardless of context.
	require_once EXTRACHILL_CLI_PATH . 'inc/Autoloader.php';
	\ExtraChill\CLI\Autoloader::register( EXTRACHILL_CLI_PATH );

	$wp = 'wp --allow-root --path=' . ABSPATH;

	\DataMachine\Engine\AI\SectionRegistry::register( 'AGENTS.md', 'extrachill-cli', 50, function () use ( $wp ) {
		return \ExtraChill\CLI\AgentsMdSection::render( $wp );
	}, array(
		'label'       => 'Extra Chill CLI',
		'description' => 'Platform-specific WP-CLI commands for Extra Chill.',
	) );
}, 22 );

// Only load CLI commands in WP-CLI context.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// PSR-4 autoloader for ExtraChill\CLI namespace.
require_once EXTRACHILL_CLI_PATH . 'inc/Autoloader.php';
\ExtraChill\CLI\Autoloader::register( EXTRACHILL_CLI_PATH );

// Register commands.
require_once EXTRACHILL_CLI_PATH . 'inc/bootstrap.php';
