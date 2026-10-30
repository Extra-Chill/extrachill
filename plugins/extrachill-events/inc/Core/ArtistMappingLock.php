<?php
/**
 * Events artist mapping advisory lock.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Serializes claims for one exact Events artist term. */
final class ArtistMappingLock {
	/**
	 * Locks held by this request.
	 *
	 * @var array<string,bool>
	 */
	private static $held = array();

	/**
	 * Acquire one Events artist mapping lock.
	 *
	 * @param int $events_term_id Events artist term ID.
	 * @return string|\WP_Error Lock name or a stable failure.
	 */
	public static function acquire( int $events_term_id ) {
		global $wpdb;
		if ( $events_term_id < 1 || preg_match( '/sqlite|pgsql|postgres/', strtolower( get_class( $wpdb ) ) ) ) {
			return new \WP_Error( 'events_artist_mapping_lock_unsupported', __( 'Artist mapping serialization requires MySQL advisory locks.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$lock_name = 'ec_events_artist_mapping_' . $events_term_id;
		if ( ! empty( self::$held[ $lock_name ] ) ) {
			return new \WP_Error( 'events_artist_mapping_lock_busy', __( 'This artist mapping is already being changed.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact mapping advisory lock.
		if ( '1' !== (string) $acquired ) {
			return new \WP_Error( 'events_artist_mapping_lock_failed', __( 'Artist mapping serialization is temporarily unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		self::$held[ $lock_name ] = true;
		return $lock_name;
	}

	/**
	 * Release a previously acquired mapping lock without dropping failed tracking.
	 *
	 * @param string $lock_name Lock name returned by acquire().
	 * @return true|\WP_Error True on release or a stable failure.
	 */
	public static function release( string $lock_name ) {
		global $wpdb;
		if ( empty( self::$held[ $lock_name ] ) ) {
			return true;
		}
		$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact mapping advisory unlock.
		if ( '1' !== (string) $released ) {
			return new \WP_Error( 'events_artist_mapping_release_failed', __( 'Artist mapping lock cleanup failed.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		unset( self::$held[ $lock_name ] );
		return true;
	}
}
