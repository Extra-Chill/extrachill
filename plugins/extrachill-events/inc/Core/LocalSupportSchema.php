<?php
/**
 * Private event-scoped local support storage.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the site-scoped local support schema. */
class LocalSupportSchema {

	public const SCHEMA_VERSION = '1';
	public const VERSION_OPTION = 'extrachill_events_local_support_schema_version';

	/** Get the support requests table. */
	public static function requests_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_local_support_requests';
	}

	/** Get the artist interests table. */
	public static function interests_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_local_support_interests';
	}

	/** Get the append-only activity table. */
	public static function activity_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_local_support_activity';
	}

	/** Install all local support tables. */
	public static function install() {
		global $wpdb;

		$requests  = self::requests_table();
		$interests = self::interests_table();
		$activity  = self::activity_table();
		$charset   = $wpdb->get_charset_collate();

		$requests_sql = "CREATE TABLE {$requests} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			event_id BIGINT UNSIGNED NOT NULL,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			booking_id BIGINT UNSIGNED NULL,
			organizer_type VARCHAR(16) NOT NULL,
			organizer_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'open',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			created_by_user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY event_id (event_id),
			KEY organizer_status (organizer_type, organizer_id, status),
			KEY venue_status_created (venue_term_id, status, created_at)
		) ENGINE=InnoDB {$charset};";

		$interests_sql = "CREATE TABLE {$interests} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			request_id BIGINT UNSIGNED NOT NULL,
			artist_term_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'interested',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			contact_payload LONGTEXT NULL,
			consent_fields LONGTEXT NULL,
			consent_version BIGINT UNSIGNED NOT NULL DEFAULT '0',
			consented_by_user_id BIGINT UNSIGNED NULL,
			consented_at DATETIME NULL,
			revoked_by_user_id BIGINT UNSIGNED NULL,
			revoked_at DATETIME NULL,
			created_by_user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY request_artist (request_id, artist_term_id),
			KEY request_status_created (request_id, status, created_at),
			KEY artist_status_created (artist_term_id, status, created_at)
		) ENGINE=InnoDB {$charset};";

		$activity_sql = "CREATE TABLE {$activity} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			request_id BIGINT UNSIGNED NOT NULL,
			interest_id BIGINT UNSIGNED NULL,
			kind VARCHAR(64) NOT NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL,
			idempotency_key VARCHAR(191) NOT NULL,
			request_hash CHAR(64) NOT NULL,
			result_version BIGINT UNSIGNED NOT NULL,
			payload LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY request_idempotency (request_id, idempotency_key),
			KEY request_created (request_id, created_at, id),
			KEY interest_created (interest_id, created_at, id),
			KEY kind_created (kind, created_at)
		) ENGINE=InnoDB {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $requests_sql );
		$requests_error = (string) $wpdb->last_error;
		dbDelta( $interests_sql );
		$interests_error = (string) $wpdb->last_error;
		dbDelta( $activity_sql );
		$activity_error = (string) $wpdb->last_error;
		if ( '' !== $requests_error || '' !== $interests_error || '' !== $activity_error ) {
			return new \WP_Error(
				'local_support_schema_install_failed',
				__( 'The local support schema could not be installed.', 'extrachill-events' ),
				compact( 'requests_error', 'interests_error', 'activity_error' )
			);
		}
		$health = self::health();
		if ( is_wp_error( $health ) ) {
			return $health;
		}
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
		return true;
	}

	/** Install only when stale. */
	public static function maybe_install() {
		return self::is_ready() ? true : self::install();
	}

	/** Return the cheap readiness signal. */
	public static function is_ready(): bool {
		return self::SCHEMA_VERSION === (string) get_option( self::VERSION_OPTION, '' );
	}

	/** Verify required tables and uniqueness contracts. */
	public static function health() {
		global $wpdb;
		$contracts = array(
			self::requests_table()  => array( 'public_id', 'event_id' ),
			self::interests_table() => array( 'public_id', 'request_artist' ),
			self::activity_table()  => array( 'request_idempotency' ),
		);
		foreach ( $contracts as $table => $unique_indexes ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema health cannot use cached data.
			if ( $found !== $table || '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'local_support_schema_table_missing', __( 'A local support table is missing.', 'extrachill-events' ), array( 'table' => $table ) );
			}
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema health cannot use cached data.
			if ( 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) ) ) {
				return new \WP_Error( 'local_support_schema_engine_invalid', __( 'Local support storage must be transactional.', 'extrachill-events' ), array( 'table' => $table ) );
			}
			$indexes      = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
			$found_unique = array();
			foreach ( (array) $indexes as $index ) {
				if ( 0 === (int) $index['Non_unique'] ) {
					$found_unique[] = (string) $index['Key_name'];
				}
			}
			foreach ( $unique_indexes as $index ) {
				if ( ! in_array( $index, $found_unique, true ) ) {
					return new \WP_Error(
						'local_support_schema_index_missing',
						__( 'A local support uniqueness contract is missing.', 'extrachill-events' ),
						array(
							'table' => $table,
							'index' => $index,
						)
					);
				}
			}
		}
		return true;
	}
}
