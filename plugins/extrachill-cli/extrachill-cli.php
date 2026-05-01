<?php
/**
 * Plugin Name: Extra Chill CLI
 * Plugin URI: https://extrachill.com
 * Description: WP-CLI command surface for the Extra Chill platform. Wraps abilities from feature plugins into a unified `wp extrachill` namespace.
 * Version: 0.11.0
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

define( 'EXTRACHILL_CLI_VERSION', '0.11.0' );
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

	$wp = 'wp --allow-root --path=' . ABSPATH;

	\DataMachine\Engine\AI\SectionRegistry::register( 'AGENTS.md', 'extrachill-cli', 50, function () use ( $wp ) {
		return <<<MD
### Extra Chill CLI

Platform-specific tooling wrapping common operations into unified commands.
Discover everything: `{$wp} extrachill --help`

- `{$wp} extrachill artists` — artist profile management and search
- `{$wp} extrachill events` — events calendar operations
- `{$wp} extrachill seo` — SEO audit, meta tags, and structured data
- `{$wp} extrachill analytics` — analytics and traffic reports
- `{$wp} extrachill newsletter` — newsletter campaigns and Sendy integration
- `{$wp} extrachill community` — community forum operations
- `{$wp} extrachill users` — user management and team membership
- `{$wp} extrachill venues` — venue lookups
- `{$wp} extrachill shows` — show/concert operations
- `{$wp} extrachill cache` — object cache and page cache management
- `{$wp} extrachill tools` — miscellaneous platform tools
- `{$wp} extrachill giveaway` — giveaway management

All commands support `--help` for subcommand discovery.
MD;
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
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'ExtraChill\\CLI\\';
		$len    = strlen( $prefix );

		if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
			return;
		}

		$relative = substr( $class_name, $len );
		$file     = EXTRACHILL_CLI_PATH . 'inc/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

// Register commands.
require_once EXTRACHILL_CLI_PATH . 'inc/bootstrap.php';
