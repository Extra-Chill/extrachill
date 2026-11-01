<?php
/**
 * Events-owned advisory locking for venue interval writers.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueLock {

	/** Keep the deployed lock domain byte-identical, including the site prefix. */
	public static function name( int $venue_id ): string {
		global $wpdb;
		return 'ecbv:' . sha1( $wpdb->prefix . 'ec_booking_holds:' . $venue_id );
	}

	/** Acquire a named advisory lock; callers retain their own error contracts. */
	public static function acquire( string $name ): bool {
		global $wpdb;
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cross-connection venue serialization.
		return 1 === (int) $result && '' === (string) $wpdb->last_error;
	}

	/** Release only a lock acquired by the caller on this connection. */
	public static function release( string $name ): bool {
		global $wpdb;
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-owned advisory lock.
		return 1 === (int) $result && '' === (string) $wpdb->last_error;
	}
}
