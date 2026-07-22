<?php
/**
 * Venue booking table installation.
 *
 * Storage is intentionally scoped to the current Events-site route through
 * `$wpdb->prefix`. Callers must execute on the Events site; repositories do
 * not switch blogs around operational queries.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns and verifies the site-scoped private booking schema. */
class BookingSchema {

	public const SCHEMA_VERSION = '1';
	public const VERSION_OPTION = 'extrachill_events_booking_schema_version';
	public const FAILURE_OPTION = 'extrachill_events_booking_schema_error';

	/** Get the bookings table for the current site. */
	public static function bookings_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_bookings';
	}

	/** Get the booking activity table for the current site. */
	public static function activity_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_booking_activity';
	}

	/** Create or repair both tables, stamping the version only after verification. */
	public static function install() {
		global $wpdb;

		$bookings = self::bookings_table();
		$activity = self::activity_table();
		$charset  = $wpdb->get_charset_collate();

		$bookings_sql = "CREATE TABLE {$bookings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			artist_term_id BIGINT UNSIGNED NULL,
			artist_profile_id BIGINT UNSIGNED NULL,
			artist_name VARCHAR(255) NOT NULL,
			submitter_user_id BIGINT UNSIGNED NULL,
			contact_name VARCHAR(255) NULL,
			contact_email VARCHAR(255) NULL,
			contact_phone VARCHAR(64) NULL,
			space_key VARCHAR(64) NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'inquiry',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			assignee_user_id BIGINT UNSIGNED NULL,
			requested_start_at DATETIME NULL,
			requested_end_at DATETIME NULL,
			intake_payload LONGTEXT NOT NULL,
			deal_payload LONGTEXT NULL,
			event_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY event_id (event_id),
			KEY venue_status_created (venue_term_id, status, created_at),
			KEY venue_requested_start (venue_term_id, requested_start_at),
			KEY artist_term_created (artist_term_id, created_at),
			KEY artist_profile_created (artist_profile_id, created_at),
			KEY assignee_status (assignee_user_id, status),
			KEY status_updated (status, updated_at)
		) ENGINE=InnoDB {$charset};";

		$activity_sql = "CREATE TABLE {$activity} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(64) NOT NULL,
			actor_type VARCHAR(32) NOT NULL DEFAULT 'system',
			actor_id BIGINT UNSIGNED NULL,
			direction VARCHAR(16) NULL,
			channel VARCHAR(32) NULL,
			payload LONGTEXT NOT NULL,
			external_id VARCHAR(191) NULL,
			idempotency_key VARCHAR(191) NULL,
			occurred_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY booking_idempotency (booking_id, idempotency_key),
			KEY booking_occurred (booking_id, occurred_at, id),
			KEY kind_occurred (kind, occurred_at),
			KEY channel_external (channel, external_id)
		) ENGINE=InnoDB {$charset};";

		$repair = self::drop_conflicting_indexes();
		if ( is_wp_error( $repair ) ) {
			self::record_failure( $repair );
			return $repair;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $bookings_sql );
		$bookings_error = (string) $wpdb->last_error;
		dbDelta( $activity_sql );
		$activity_error = (string) $wpdb->last_error;
		if ( '' !== $bookings_error || '' !== $activity_error ) {
			$error = new \WP_Error(
				'booking_schema_dbdelta_failed',
				__( 'The booking schema could not be reconciled.', 'extrachill-events' ),
				array(
					'bookings_error' => $bookings_error,
					'activity_error' => $activity_error,
				)
			);
			self::record_failure( $error );
			return $error;
		}

		$health = self::health();
		if ( is_wp_error( $health ) ) {
			self::record_failure( $health );
			return $health;
		}

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
		delete_option( self::FAILURE_OPTION );
		return true;
	}

	/** Install only when the stored schema version is stale. */
	public static function maybe_install() {
		if ( self::SCHEMA_VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
			return true;
		}
		return self::install();
	}

	/** Verify tables, column attributes, indexes, and uniqueness contracts. */
	public static function health() {
		global $wpdb;

		foreach ( self::contracts() as $table => $contract ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Installation health cannot be cached.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( '' !== (string) $wpdb->last_error ) {
				return self::database_error( __( 'Could not inspect the booking tables.', 'extrachill-events' ), $table );
			}
			if ( $found !== $table ) {
				return new \WP_Error( 'booking_schema_table_missing', __( 'A required booking table is missing.', 'extrachill-events' ), array( 'table' => $table ) );
			}

			$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
			if ( '' !== (string) $wpdb->last_error ) {
				return self::database_error( __( 'Could not inspect booking columns.', 'extrachill-events' ), $table );
			}
			$found_columns = array();
			foreach ( (array) $columns as $column ) {
				$found_columns[ $column['Field'] ] = self::normalize_column( $column );
			}
			foreach ( $contract['columns'] as $name => $required_column ) {
				if ( ! isset( $found_columns[ $name ] ) ) {
					return new \WP_Error(
						'booking_schema_columns_missing',
						__( 'A required booking column is missing.', 'extrachill-events' ),
						array(
							'table'  => $table,
							'column' => $name,
						)
					);
				}
				foreach ( $required_column as $attribute => $required_value ) {
					if ( $required_value !== $found_columns[ $name ][ $attribute ] ) {
						return new \WP_Error(
							'booking_schema_column_invalid',
							__( 'A required booking column has incompatible attributes.', 'extrachill-events' ),
							array(
								'table'     => $table,
								'column'    => $name,
								'attribute' => $attribute,
								'expected'  => $required_value,
								'actual'    => $found_columns[ $name ][ $attribute ],
							)
						);
					}
				}
			}

			$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
			if ( '' !== (string) $wpdb->last_error ) {
				return self::database_error( __( 'Could not inspect booking indexes.', 'extrachill-events' ), $table );
			}
			$found_indexes = self::normalize_indexes( (array) $indexes );
			foreach ( $contract['indexes'] as $name => $required_index ) {
				if ( ! isset( $found_indexes[ $name ] ) || $required_index !== $found_indexes[ $name ] ) {
					return new \WP_Error(
						'booking_schema_index_missing',
						__( 'A required booking index is missing or invalid.', 'extrachill-events' ),
						array(
							'table'      => $table,
							'index'      => $name,
							'definition' => $required_index,
						)
					);
				}
			}
		}
		return true;
	}

	/** Backward-compatible table existence check backed by full schema health. */
	public static function tables_exist(): bool {
		return true === self::health();
	}

	/** Drop only required same-name indexes whose definitions block dbDelta repair. */
	private static function drop_conflicting_indexes() {
		global $wpdb;

		foreach ( self::contracts() as $table => $contract ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Installation repair cannot be cached.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( '' !== (string) $wpdb->last_error ) {
				return self::database_error( __( 'Could not inspect booking tables before repair.', 'extrachill-events' ), $table );
			}
			if ( $found !== $table ) {
				continue;
			}
			$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
			if ( '' !== (string) $wpdb->last_error ) {
				return self::database_error( __( 'Could not inspect booking indexes before repair.', 'extrachill-events' ), $table );
			}
			$found_indexes = self::normalize_indexes( (array) $indexes );
			foreach ( $contract['indexes'] as $name => $required_index ) {
				if ( ! isset( $found_indexes[ $name ] ) || $required_index === $found_indexes[ $name ] ) {
					continue;
				}
				if ( 'PRIMARY' === $name ) {
					$primary_columns = '`' . implode( '`, `', $required_index['columns'] ) . '`';
					$drop            = "ALTER TABLE `{$table}` DROP PRIMARY KEY, ADD PRIMARY KEY ({$primary_columns})";
				} else {
					$drop = "ALTER TABLE `{$table}` DROP INDEX `{$name}`";
				}
				$result = $wpdb->query( $drop ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal table and index names from the schema contract.
				if ( false === $result ) {
					return new \WP_Error(
						'booking_schema_index_repair_failed',
						__( 'A malformed booking index could not be removed for repair.', 'extrachill-events' ),
						array(
							'table'          => $table,
							'index'          => $name,
							'database_error' => $wpdb->last_error,
						)
					);
				}
			}
		}
		return true;
	}

	/** Return the final initial schema contract shared by health and repair. */
	private static function contracts(): array {
		$required = static function ( string $type, bool $nullable, array $attributes = array() ): array {
			return array_merge(
				array(
					'type'     => $type,
					'nullable' => $nullable,
				),
				$attributes
			);
		};
		return array(
			self::bookings_table() => array(
				'columns' => array(
					'id'                 => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'public_id'          => $required( 'char(36)', false ),
					'venue_term_id'      => $required( 'bigint unsigned', false ),
					'artist_term_id'     => $required( 'bigint unsigned', true ),
					'artist_profile_id'  => $required( 'bigint unsigned', true ),
					'artist_name'        => $required( 'varchar(255)', false ),
					'submitter_user_id'  => $required( 'bigint unsigned', true ),
					'contact_name'       => $required( 'varchar(255)', true ),
					'contact_email'      => $required( 'varchar(255)', true ),
					'contact_phone'      => $required( 'varchar(64)', true ),
					'space_key'          => $required( 'varchar(64)', true ),
					'status'             => $required( 'varchar(32)', false, array( 'default' => 'inquiry' ) ),
					'version'            => $required( 'bigint unsigned', false, array( 'default' => '1' ) ),
					'assignee_user_id'   => $required( 'bigint unsigned', true ),
					'requested_start_at' => $required( 'datetime', true ),
					'requested_end_at'   => $required( 'datetime', true ),
					'intake_payload'     => $required( 'longtext', false ),
					'deal_payload'       => $required( 'longtext', true ),
					'event_id'           => $required( 'bigint unsigned', true ),
					'created_at'         => $required( 'datetime', false ),
					'updated_at'         => $required( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'                => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'public_id'              => array(
						'unique'  => true,
						'columns' => array( 'public_id' ),
					),
					'event_id'               => array(
						'unique'  => true,
						'columns' => array( 'event_id' ),
					),
					'venue_status_created'   => array(
						'unique'  => false,
						'columns' => array( 'venue_term_id', 'status', 'created_at' ),
					),
					'venue_requested_start'  => array(
						'unique'  => false,
						'columns' => array( 'venue_term_id', 'requested_start_at' ),
					),
					'artist_term_created'    => array(
						'unique'  => false,
						'columns' => array( 'artist_term_id', 'created_at' ),
					),
					'artist_profile_created' => array(
						'unique'  => false,
						'columns' => array( 'artist_profile_id', 'created_at' ),
					),
					'assignee_status'        => array(
						'unique'  => false,
						'columns' => array( 'assignee_user_id', 'status' ),
					),
					'status_updated'         => array(
						'unique'  => false,
						'columns' => array( 'status', 'updated_at' ),
					),
				),
			),
			self::activity_table() => array(
				'columns' => array(
					'id'              => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'booking_id'      => $required( 'bigint unsigned', false ),
					'kind'            => $required( 'varchar(64)', false ),
					'actor_type'      => $required( 'varchar(32)', false, array( 'default' => 'system' ) ),
					'actor_id'        => $required( 'bigint unsigned', true ),
					'direction'       => $required( 'varchar(16)', true ),
					'channel'         => $required( 'varchar(32)', true ),
					'payload'         => $required( 'longtext', false ),
					'external_id'     => $required( 'varchar(191)', true ),
					'idempotency_key' => $required( 'varchar(191)', true ),
					'occurred_at'     => $required( 'datetime', false ),
					'created_at'      => $required( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'             => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'booking_idempotency' => array(
						'unique'  => true,
						'columns' => array( 'booking_id', 'idempotency_key' ),
					),
					'booking_occurred'    => array(
						'unique'  => false,
						'columns' => array( 'booking_id', 'occurred_at', 'id' ),
					),
					'kind_occurred'       => array(
						'unique'  => false,
						'columns' => array( 'kind', 'occurred_at' ),
					),
					'channel_external'    => array(
						'unique'  => false,
						'columns' => array( 'channel', 'external_id' ),
					),
				),
			),
		);
	}

	/** Normalize one SHOW COLUMNS row to the declared contract shape. */
	private static function normalize_column( array $column ): array {
		$type = preg_replace( '/\((?:8|11|20)\)(?=\s+unsigned)/', '', strtolower( trim( (string) ( $column['Type'] ?? '' ) ) ) );
		return array(
			'type'     => preg_replace( '/\s+/', ' ', $type ),
			'nullable' => 'YES' === strtoupper( (string) ( $column['Null'] ?? '' ) ),
			'default'  => null === ( $column['Default'] ?? null ) ? null : (string) $column['Default'],
			'extra'    => strtolower( trim( (string) ( $column['Extra'] ?? '' ) ) ),
		);
	}

	/** Normalize SHOW INDEX rows to exact uniqueness and ordered columns. */
	private static function normalize_indexes( array $indexes ): array {
		$normalized = array();
		foreach ( $indexes as $index ) {
			$name                          = $index['Key_name'];
			$normalized[ $name ]['unique'] = 0 === (int) $index['Non_unique'];
			$normalized[ $name ]['columns'][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
		}
		foreach ( $normalized as &$definition ) {
			ksort( $definition['columns'] );
			$definition['columns'] = array_values( $definition['columns'] );
		}
		unset( $definition );
		return $normalized;
	}

	/** Build a consistent database inspection error. */
	private static function database_error( string $message, string $table ): \WP_Error {
		global $wpdb;
		return new \WP_Error(
			'booking_schema_db_error',
			$message,
			array(
				'table'          => $table,
				'database_error' => $wpdb->last_error,
			)
		);
	}

	/** Record an actionable install failure without advancing the version. */
	private static function record_failure( \WP_Error $error ): void {
		global $wpdb;
		update_option(
			self::FAILURE_OPTION,
			array(
				'code'        => $error->get_error_code(),
				'message'     => $error->get_error_message(),
				'data'        => $error->get_error_data(),
				'failed_at'   => gmdate( 'Y-m-d H:i:s' ),
				'site_prefix' => $wpdb->prefix,
			),
			false
		);
	}
}
