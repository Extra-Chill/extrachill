<?php
/**
 * Optional Data Machine Events integration.
 *
 * @package ExtraChillEvents\Providers
 */

namespace ExtraChillEvents\Providers;

defined( 'ABSPATH' ) || exit;

/** Registers the sibling plugin adapter only when its public API is present. */
final class DataMachineEventsProvider {

	/**
	 * Whether the optional adapter has initialized.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/** Register the optional integration without gating unrelated features. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/data-machine-events/init.php';
		if ( defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ) {
			extrachill_events_init_data_machine_integration();
		}
	}
}
