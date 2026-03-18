<?php
/**
 * Plugin Name: Extra Chill CLI
 * Plugin URI: https://extrachill.com
 * Description: WP-CLI command surface for the Extra Chill platform. Wraps abilities from feature plugins into a unified `wp extrachill` namespace.
 * Version: 0.5.0
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

define( 'EXTRACHILL_CLI_VERSION', '0.5.0' );
define( 'EXTRACHILL_CLI_PATH', plugin_dir_path( __FILE__ ) );

// Only load in WP-CLI context.
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
