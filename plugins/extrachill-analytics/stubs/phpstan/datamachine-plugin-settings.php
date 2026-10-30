<?php
/**
 * PHPStan stub for Data Machine plugin settings.
 *
 * `FatalRateAlarmTask::executeTask()` gates manual runs on a DM PluginSetting.
 * The class lives in the Data Machine plugin (outside this component's analysis
 * scope), so this stub gives PHPStan the minimal shape for `PluginSettings::get()`.
 *
 * @package ExtraChill\Analytics\Stubs
 */

namespace DataMachine\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Static-analysis shape for the external Data Machine PluginSettings class.
 */
class PluginSettings {

	/**
	 * @param mixed $fallback Fallback value returned when the setting is unset.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
	}
}
