<?php
/**
 * Re-entrancy guard for artist identity pushes to the Link Page.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Re-entrancy guard for identity pushes.
 */
class ArtistDataSyncManager {
	/**
	 * Whether a push is in progress.
	 *
	 * @var bool
	 */
	private static $is_syncing = false;

	public static function is_syncing() {
		return self::$is_syncing;
	}

	public static function start_sync() {
		self::$is_syncing = true;
	}

	public static function stop_sync() {
		self::$is_syncing = false;
	}
}
