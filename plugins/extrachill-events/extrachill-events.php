<?php
/**
 * Plugin Name: Extra Chill Events
 * Plugin URI: https://extrachill.com
 * Description: Calendar integration with template overrides, data-machine-events badge/button styling, breadcrumb system, and related events for events.extrachill.com.
 * Version: 0.64.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * Requires Plugins: data-machine, data-machine-events
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-events
 * Requires at least: 6.9
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Network: false
 *
 * @package ExtraChillEvents
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_EVENTS_VERSION', '0.64.0' );
define( 'EXTRACHILL_EVENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_EVENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACHILL_EVENTS_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_EVENTS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

$extrachill_events_autoload = EXTRACHILL_EVENTS_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $extrachill_events_autoload ) ) {
	require_once $extrachill_events_autoload;
}

require_once __DIR__ . '/inc/Providers/CliProvider.php';
require_once __DIR__ . '/inc/Providers/IngestionProvider.php';
require_once __DIR__ . '/inc/Providers/AdministrationProvider.php';
require_once __DIR__ . '/inc/Providers/LifecycleProvider.php';
require_once __DIR__ . '/inc/Providers/CoreRuntimeProvider.php';
require_once __DIR__ . '/inc/Providers/ArtistUrlImportProvider.php';
require_once __DIR__ . '/inc/Providers/VenueBookingProvider.php';
require_once __DIR__ . '/inc/Providers/PublicExperienceProvider.php';
require_once __DIR__ . '/inc/Providers/AbilitiesProvider.php';
require_once __DIR__ . '/inc/Providers/DataMachineEventsProvider.php';
require_once __DIR__ . '/inc/Core/Plugin.php';

/** Return the initialized Extra Chill Events facade. */
function extrachill_events() {
	return ExtraChillEvents::get_instance();
}

extrachill_events();
