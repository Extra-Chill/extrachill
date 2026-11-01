<?php
/**
 * Venue venue table installation.
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

/** Owns and verifies the site-scoped private venue schema. */
class VenueSchema {

	public const SCHEMA_VERSION = '1';
	public const VERSION_OPTION = 'extrachill_events_venue_schema_version';
	public const FAILURE_OPTION = 'extrachill_events_venue_schema_error';

	/** Get the venue membership table for the current site. */
	public static function memberships_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_venue_members';
	}

	/** Get the venue claims table for the current site. */
	public static function claims_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_venue_claims';
	}

	/** Get the venue invitations table for the current site. */
	public static function invitations_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_venue_invitations';
	}

	/** Get the privacy-safe venue onboarding audit table. */
	public static function onboarding_audit_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_venue_onboarding_audit';
	}

	/** Create or repair all tables, stamping the version only after verification. */
	public static function install() {
		/** @var \wpdb $wpdb */
		global $wpdb;
		$schema_lock = null;
		$lock_guard  = null;
		if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof \mysqli ) {
			$schema_lock = 'ec_venue_schema_' . substr( hash( 'sha256', (string) \constant( 'DB_NAME' ) . "\0" . $wpdb->prefix ), 0, 40 );
			$acquired    = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 30)', $schema_lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes concurrent installers for this site prefix.
			if ( 1 !== (int) $acquired ) {
				return new \WP_Error( 'venue_schema_lock_failed', __( 'The venue schema installer could not acquire its site lock.', 'extrachill-events' ) );
			}
			$lock_guard = new class( $wpdb, $schema_lock ) {
				/** Database session holding the advisory lock.
				 *
				 * @var \wpdb
				 */
				private $database;
				/** Exact site-scoped advisory lock name.
				 *
				 * @var string
				 */
				private $name;

				/** Retain the lock owner until every install return path completes.
				 *
				 * @param \wpdb  $database Database session.
				 * @param string $name Lock name.
				 */
				public function __construct( $database, string $name ) {
					$this->database = $database;
					$this->name     = $name;
				}

				/** Release the exact advisory lock. */
				public function __destruct() {
					$this->database->get_var( $this->database->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the exact installer lock on every return path.
				}
			};
		}

		// Adopt an already-current venue schema without touching production data.
		if ( true === self::health() ) {
			update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
			delete_option( self::FAILURE_OPTION );
			return true;
		}

		$members = self::memberships_table();
		$claims  = self::claims_table();
		$invites = self::invitations_table();
		$audit   = self::onboarding_audit_table();
		$charset = $wpdb->get_charset_collate();

		$members_sql = "CREATE TABLE {$members} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			is_owner TINYINT UNSIGNED NOT NULL DEFAULT '0',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			created_by_user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			revoked_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY venue_user (venue_term_id, user_id),
			KEY user_status_venue (user_id, status, venue_term_id),
			KEY venue_status_owner (venue_term_id, status, is_owner)
		) ENGINE=InnoDB {$charset};";

		$claims_sql = "CREATE TABLE {$claims} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			claimant_user_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			reviewed_by_user_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			resolved_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY venue_claimant (venue_term_id, claimant_user_id),
			KEY status_created (status, created_at),
			KEY venue_status (venue_term_id, status),
			KEY claimant_status_venue (claimant_user_id, status, venue_term_id)
		) ENGINE=InnoDB {$charset};";

		$invites_sql = "CREATE TABLE {$invites} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			is_owner TINYINT UNSIGNED NOT NULL DEFAULT '0',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			token_hash CHAR(64) NOT NULL,
			email_hash CHAR(64) NOT NULL,
			account_created TINYINT UNSIGNED NOT NULL DEFAULT '0',
			delivery_id CHAR(36) NOT NULL,
			delivery_status VARCHAR(20) NOT NULL DEFAULT 'queued',
			delivery_attempts BIGINT UNSIGNED NOT NULL DEFAULT '0',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			invited_by_user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			resolved_at DATETIME NULL,
			delivered_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY delivery_id (delivery_id),
			UNIQUE KEY venue_user (venue_term_id, user_id),
			KEY venue_status (venue_term_id, status),
			KEY status_expiration (status, expires_at),
			KEY user_status_venue (user_id, status, venue_term_id)
		) ENGINE=InnoDB {$charset};";

		$audit_sql = "CREATE TABLE {$audit} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			entity_type VARCHAR(16) NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL,
			event VARCHAR(48) NOT NULL,
			actor_user_id BIGINT UNSIGNED NULL,
			subject_user_id BIGINT UNSIGNED NULL,
			payload LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY entity_created (entity_type, entity_id, created_at, id),
			KEY venue_created (venue_term_id, created_at, id),
			KEY event_created (event, created_at)
		) ENGINE=InnoDB {$charset};";

		$repair = self::drop_conflicting_indexes();
		if ( is_wp_error( $repair ) ) {
			self::record_failure( $repair );
			return $repair;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $members_sql );
		$members_error = (string) $wpdb->last_error;
		dbDelta( $claims_sql );
		$claims_error = (string) $wpdb->last_error;
		dbDelta( $invites_sql );
		$invites_error = (string) $wpdb->last_error;
		dbDelta( $audit_sql );
		$audit_error = (string) $wpdb->last_error;
		if ( '' !== $members_error || '' !== $claims_error || '' !== $invites_error || '' !== $audit_error ) {
			$error = new \WP_Error(
				'venue_schema_dbdelta_failed',
				__( 'The venue schema could not be reconciled.', 'extrachill-events' ),
				array(
					'members_error' => $members_error,
					'claims_error'  => $claims_error,
					'invites_error' => $invites_error,
					'audit_error'   => $audit_error,
				)
			);
			self::record_failure( $error );
			return $error;
		}

		$membership_migration = self::migrate_membership_authority();
		if ( is_wp_error( $membership_migration ) ) {
			self::record_failure( $membership_migration );
			return $membership_migration;
		}

		$engine_repair = self::repair_storage_engines();
		if ( is_wp_error( $engine_repair ) ) {
			self::record_failure( $engine_repair );
			return $engine_repair;
		}

		$health = self::health();
		if ( is_wp_error( $health ) ) {
			self::record_failure( $health );
			return $health;
		}

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
		delete_option( self::FAILURE_OPTION );
		unset( $lock_guard );
		return true;
	}

	/** Install only when the stored schema version is stale. */
	public static function maybe_install() {
		if ( self::SCHEMA_VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
			return true;
		}
		return self::install();
	}

	/** Check the cheap request-time readiness signal established by install. */
	public static function is_ready(): bool {
		return self::SCHEMA_VERSION === (string) get_option( self::VERSION_OPTION, '' );
	}

	/** Verify tables, column attributes, indexes, and uniqueness contracts. */
	public static function health() {
		global $wpdb;

		foreach ( self::contracts() as $table => $contract ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Installation health cannot be cached.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( '' !== (string) $wpdb->last_error ) {
				return self::database_error( __( 'Could not inspect the venue tables.', 'extrachill-events' ), $table );
			}
			if ( $found !== $table ) {
				return new \WP_Error( 'venue_schema_table_missing', __( 'A required venue table is missing.', 'extrachill-events' ), array( 'table' => $table ) );
			}
			if ( isset( $contract['engine'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Installation health cannot be cached.
				$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
				if ( '' !== (string) $wpdb->last_error ) {
					return self::database_error( __( 'Could not inspect the venue table engine.', 'extrachill-events' ), $table );
				}
				$engine = strtolower( (string) ( $status['Engine'] ?? '' ) );
				if ( $contract['engine'] !== $engine ) {
					return new \WP_Error(
						'venue_schema_engine_invalid',
						__( 'A venue table does not use the required transactional engine.', 'extrachill-events' ),
						array(
							'table'    => $table,
							'expected' => $contract['engine'],
							'actual'   => $engine,
						)
					);
				}
			}

			$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
			if ( '' !== (string) $wpdb->last_error ) {
				return self::database_error( __( 'Could not inspect venue columns.', 'extrachill-events' ), $table );
			}
			$found_columns = array();
			foreach ( (array) $columns as $column ) {
				$found_columns[ $column['Field'] ] = self::normalize_column( $column );
			}
			foreach ( $contract['columns'] as $name => $required_column ) {
				if ( ! isset( $found_columns[ $name ] ) ) {
					return new \WP_Error(
						'venue_schema_columns_missing',
						__( 'A required venue column is missing.', 'extrachill-events' ),
						array(
							'table'  => $table,
							'column' => $name,
						)
					);
				}
				foreach ( $required_column as $attribute => $required_value ) {
					if ( $required_value !== $found_columns[ $name ][ $attribute ] ) {
						return new \WP_Error(
							'venue_schema_column_invalid',
							__( 'A required venue column has incompatible attributes.', 'extrachill-events' ),
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
			foreach ( $contract['absent_columns'] ?? array() as $name ) {
				if ( isset( $found_columns[ $name ] ) ) {
					return new \WP_Error(
						'venue_schema_obsolete_column_present',
						__( 'An obsolete venue column is still present.', 'extrachill-events' ),
						array(
							'table'  => $table,
							'column' => $name,
						)
					);
				}
			}

			$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
			if ( '' !== (string) $wpdb->last_error ) {
				return self::database_error( __( 'Could not inspect venue indexes.', 'extrachill-events' ), $table );
			}
			$found_indexes = self::normalize_indexes( (array) $indexes );
			foreach ( $contract['absent_indexes'] ?? array() as $name ) {
				if ( isset( $found_indexes[ $name ] ) ) {
					return new \WP_Error(
						'venue_schema_obsolete_index_present',
						__( 'An obsolete venue index is still present.', 'extrachill-events' ),
						array(
							'table' => $table,
							'index' => $name,
						)
					);
				}
			}
			foreach ( $contract['indexes'] as $name => $required_index ) {
				if ( ! isset( $found_indexes[ $name ] ) || $required_index !== $found_indexes[ $name ] ) {
					return new \WP_Error(
						'venue_schema_index_missing',
						__( 'A required venue index is missing or invalid.', 'extrachill-events' ),
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
				return self::database_error( __( 'Could not inspect venue tables before repair.', 'extrachill-events' ), $table );
			}
			if ( $found !== $table ) {
				continue;
			}
			$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
			if ( '' !== (string) $wpdb->last_error ) {
				return self::database_error( __( 'Could not inspect venue indexes before repair.', 'extrachill-events' ), $table );
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
						'venue_schema_index_repair_failed',
						__( 'A malformed venue index could not be removed for repair.', 'extrachill-events' ),
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

	/** Convert required tables to their declared transactional engine. */
	private static function repair_storage_engines() {
		global $wpdb;

		foreach ( self::contracts() as $table => $contract ) {
			if ( ! isset( $contract['engine'] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Installation repair cannot be cached.
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error ) {
				return self::database_error( __( 'Could not inspect the venue table engine for repair.', 'extrachill-events' ), $table );
			}
			if ( ! is_array( $status ) ) {
				continue;
			}
			if ( strtolower( (string) ( $status['Engine'] ?? '' ) ) === $contract['engine'] ) {
				continue;
			}
			$engine = strtoupper( $contract['engine'] );
			$result = $wpdb->query( "ALTER TABLE `{$table}` ENGINE={$engine}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table and engine come from the private schema contract.
			if ( false === $result ) {
				return new \WP_Error(
					'venue_schema_engine_repair_failed',
					__( 'A venue table could not be converted to its required transactional engine.', 'extrachill-events' ),
					array(
						'table'          => $table,
						'engine'         => $contract['engine'],
						'database_error' => $wpdb->last_error,
					)
				);
			}
		}
		return true;
	}

	/** Collapse the unreleased speculative role matrix into structural ownership. */
	private static function migrate_membership_authority() {
		global $wpdb;

		$table   = self::memberships_table();
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private schema migration.
		if ( '' !== (string) $wpdb->last_error ) {
			return self::database_error( __( 'Could not inspect venue membership authority.', 'extrachill-events' ), $table );
		}
		$column_names = array_column( (array) $columns, 'Field' );
		if ( ! in_array( 'role', $column_names, true ) ) {
			return true;
		}

		$result = $wpdb->query( "UPDATE `{$table}` SET is_owner = IF(role = 'owner', 1, 0)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration from the unreleased role matrix.
		if ( false === $result ) {
			$remaining_columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Distinguishes a concurrent completed migration from a real failure.
			if ( '' !== (string) $wpdb->last_error || in_array( 'role', array_column( (array) $remaining_columns, 'Field' ), true ) ) {
				return self::database_error( __( 'Could not migrate venue membership ownership.', 'extrachill-events' ), $table );
			}
			return true;
		}

		$indexes = self::normalize_indexes( (array) $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private schema migration.
		if ( '' !== (string) $wpdb->last_error ) {
			return self::database_error( __( 'Could not inspect venue membership indexes.', 'extrachill-events' ), $table );
		}
		if ( isset( $indexes['venue_status_role'] ) && false === $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `venue_status_role`" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes the obsolete private index.
			$current_indexes = self::normalize_indexes( (array) $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Distinguishes concurrent removal from a real failure.
			if ( '' !== (string) $wpdb->last_error || isset( $current_indexes['venue_status_role'] ) ) {
				return self::database_error( __( 'Could not remove the obsolete venue membership index.', 'extrachill-events' ), $table );
			}
		}
		if ( false === $wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `role`" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes an unreleased speculative field after preserving structural ownership.
			$current_columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Distinguishes concurrent removal from a real failure.
			if ( '' !== (string) $wpdb->last_error || in_array( 'role', array_column( (array) $current_columns, 'Field' ), true ) ) {
				return self::database_error( __( 'Could not remove obsolete venue membership roles.', 'extrachill-events' ), $table );
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
		$index    = static function ( bool $unique, string ...$columns ): array {
			return array(
				'unique'  => $unique,
				'columns' => $columns,
			);
		};
		return array(
			self::memberships_table()      => array(
				'absent_columns' => array( 'role' ),
				'absent_indexes' => array( 'venue_status_role' ),
				'engine'         => 'innodb',
				'columns'        => array(
					'id'                 => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'venue_term_id'      => $required( 'bigint unsigned', false ),
					'user_id'            => $required( 'bigint unsigned', false ),
					'is_owner'           => $required( 'tinyint unsigned', false, array( 'default' => '0' ) ),
					'status'             => $required( 'varchar(20)', false, array( 'default' => 'active' ) ),
					'version'            => $required( 'bigint unsigned', false, array( 'default' => '1' ) ),
					'created_by_user_id' => $required( 'bigint unsigned', false ),
					'created_at'         => $required( 'datetime', false ),
					'updated_at'         => $required( 'datetime', false ),
					'revoked_at'         => $required( 'datetime', true ),
				),
				'indexes'        => array(
					'PRIMARY'            => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'venue_user'         => array(
						'unique'  => true,
						'columns' => array( 'venue_term_id', 'user_id' ),
					),
					'user_status_venue'  => array(
						'unique'  => false,
						'columns' => array( 'user_id', 'status', 'venue_term_id' ),
					),
					'venue_status_owner' => array(
						'unique'  => false,
						'columns' => array( 'venue_term_id', 'status', 'is_owner' ),
					),
				),
			),
			self::claims_table()           => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'                  => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'public_id'           => $required( 'char(36)', false ),
					'venue_term_id'       => $required( 'bigint unsigned', false ),
					'claimant_user_id'    => $required( 'bigint unsigned', false ),
					'status'              => $required( 'varchar(20)', false, array( 'default' => 'pending' ) ),
					'version'             => $required( 'bigint unsigned', false, array( 'default' => '1' ) ),
					'reviewed_by_user_id' => $required( 'bigint unsigned', true ),
					'created_at'          => $required( 'datetime', false ),
					'updated_at'          => $required( 'datetime', false ),
					'resolved_at'         => $required( 'datetime', true ),
				),
				'indexes' => array(
					'PRIMARY'               => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'public_id'             => array(
						'unique'  => true,
						'columns' => array( 'public_id' ),
					),
					'venue_claimant'        => array(
						'unique'  => true,
						'columns' => array( 'venue_term_id', 'claimant_user_id' ),
					),
					'status_created'        => array(
						'unique'  => false,
						'columns' => array( 'status', 'created_at' ),
					),
					'venue_status'          => array(
						'unique'  => false,
						'columns' => array( 'venue_term_id', 'status' ),
					),
					'claimant_status_venue' => array(
						'unique'  => false,
						'columns' => array( 'claimant_user_id', 'status', 'venue_term_id' ),
					),
				),
			),
			self::invitations_table()      => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'                 => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'public_id'          => $required( 'char(36)', false ),
					'venue_term_id'      => $required( 'bigint unsigned', false ),
					'user_id'            => $required( 'bigint unsigned', false ),
					'is_owner'           => $required( 'tinyint unsigned', false, array( 'default' => '0' ) ),
					'status'             => $required( 'varchar(20)', false, array( 'default' => 'pending' ) ),
					'token_hash'         => $required( 'char(64)', false ),
					'email_hash'         => $required( 'char(64)', false ),
					'account_created'    => $required( 'tinyint unsigned', false, array( 'default' => '0' ) ),
					'delivery_id'        => $required( 'char(36)', false ),
					'delivery_status'    => $required( 'varchar(20)', false, array( 'default' => 'queued' ) ),
					'delivery_attempts'  => $required( 'bigint unsigned', false, array( 'default' => '0' ) ),
					'version'            => $required( 'bigint unsigned', false, array( 'default' => '1' ) ),
					'invited_by_user_id' => $required( 'bigint unsigned', false ),
					'created_at'         => $required( 'datetime', false ),
					'updated_at'         => $required( 'datetime', false ),
					'expires_at'         => $required( 'datetime', false ),
					'resolved_at'        => $required( 'datetime', true ),
					'delivered_at'       => $required( 'datetime', true ),
				),
				'indexes' => array(
					'PRIMARY'           => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'public_id'         => array(
						'unique'  => true,
						'columns' => array( 'public_id' ),
					),
					'delivery_id'       => array(
						'unique'  => true,
						'columns' => array( 'delivery_id' ),
					),
					'venue_user'        => array(
						'unique'  => true,
						'columns' => array( 'venue_term_id', 'user_id' ),
					),
					'venue_status'      => array(
						'unique'  => false,
						'columns' => array( 'venue_term_id', 'status' ),
					),
					'status_expiration' => array(
						'unique'  => false,
						'columns' => array( 'status', 'expires_at' ),
					),
					'user_status_venue' => array(
						'unique'  => false,
						'columns' => array( 'user_id', 'status', 'venue_term_id' ),
					),
				),
			),
			self::onboarding_audit_table() => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'              => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'venue_term_id'   => $required( 'bigint unsigned', false ),
					'entity_type'     => $required( 'varchar(16)', false ),
					'entity_id'       => $required( 'bigint unsigned', false ),
					'event'           => $required( 'varchar(48)', false ),
					'actor_user_id'   => $required( 'bigint unsigned', true ),
					'subject_user_id' => $required( 'bigint unsigned', true ),
					'payload'         => $required( 'longtext', false ),
					'created_at'      => $required( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'        => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'entity_created' => array(
						'unique'  => false,
						'columns' => array( 'entity_type', 'entity_id', 'created_at', 'id' ),
					),
					'venue_created'  => array(
						'unique'  => false,
						'columns' => array( 'venue_term_id', 'created_at', 'id' ),
					),
					'event_created'  => array(
						'unique'  => false,
						'columns' => array( 'event', 'created_at' ),
					),
				),
			),
		);
	}

	/**
	 * Normalize one SHOW COLUMNS row to the declared contract shape.
	 *
	 * @param array $column Raw SHOW COLUMNS row.
	 */
	private static function normalize_column( array $column ): array {
		$type = preg_replace(
			'/\A(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)(\s+unsigned)?\z/',
			'$1$2',
			strtolower( trim( (string) ( $column['Type'] ?? '' ) ) )
		);
		return array(
			'type'     => preg_replace( '/\s+/', ' ', $type ),
			'nullable' => 'YES' === strtoupper( (string) ( $column['Null'] ?? '' ) ),
			'default'  => null === ( $column['Default'] ?? null ) ? null : (string) $column['Default'],
			'extra'    => strtolower( trim( (string) ( $column['Extra'] ?? '' ) ) ),
		);
	}

	/**
	 * Normalize SHOW INDEX rows to exact uniqueness and ordered columns.
	 *
	 * @param array $indexes Raw SHOW INDEX rows.
	 */
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

	/**
	 * Build a consistent database inspection error.
	 *
	 * @param string $message Public error message.
	 * @param string $table   Inspected table name.
	 */
	private static function database_error( string $message, string $table ): \WP_Error {
		global $wpdb;
		return new \WP_Error(
			'venue_schema_db_error',
			$message,
			array(
				'table'          => $table,
				'database_error' => $wpdb->last_error,
			)
		);
	}

	/**
	 * Record an actionable install failure without advancing the version.
	 *
	 * @param \WP_Error $error Installation failure.
	 */
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
