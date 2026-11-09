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

require_once __DIR__ . '/NetworkTeamMembersMigration.php';

/** Owns and verifies the site-scoped private venue schema. */
class VenueSchema {

	public const SCHEMA_VERSION = '4';
	public const VERSION_OPTION = 'extrachill_events_venue_schema_version';
	public const FAILURE_OPTION = 'extrachill_events_venue_schema_error';

	/** Get the venue claims table for the current site. */
	public static function claims_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_team_claims';
	}

	/** Get the venue invitations table for the current site. */
	public static function invitations_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_team_invitations';
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

		if ( ! self::is_ready() && false === $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}ec_venue_onboarding_audit`" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Version-gated removal of unused current-site storage.
			$error = new \WP_Error( 'venue_schema_drop_failed', __( 'The retired venue table could not be removed.', 'extrachill-events' ) );
			self::record_failure( $error );
			return $error;
		}

		if ( ! self::is_ready() ) {
			$retired = self::drop_empty_legacy_onboarding();
			if ( is_wp_error( $retired ) ) {
				self::record_failure( $retired );
				return $retired;
			}
		}

		$copy = NetworkTeamMembersMigration::copy_legacy();
		if ( is_wp_error( $copy ) ) {
			return $copy;
		}

		// Adopt an already-current venue schema without touching production data.
		if ( true === self::health() ) {
			update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
			delete_option( self::FAILURE_OPTION );
			return true;
		}

		$claims  = self::claims_table();
		$invites = self::invitations_table();
		$charset = $wpdb->get_charset_collate();

		$claims_sql = "CREATE TABLE {$claims} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			team_type VARCHAR(16) NOT NULL,
			team_term_id BIGINT UNSIGNED NOT NULL,
			claimant_user_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			reviewed_by_user_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			resolved_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY team_claimant (team_type, team_term_id, claimant_user_id),
			KEY status_created (team_type, status, created_at),
			KEY team_status (team_type, team_term_id, status),
			KEY claimant_status_team (claimant_user_id, status, team_type, team_term_id)
		) ENGINE=InnoDB {$charset};";

		$invites_sql = "CREATE TABLE {$invites} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			team_type VARCHAR(16) NOT NULL,
			team_term_id BIGINT UNSIGNED NOT NULL,
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
			UNIQUE KEY team_user (team_type, team_term_id, user_id),
			KEY team_status (team_type, team_term_id, status),
			KEY status_expiration (team_type, status, expires_at),
			KEY user_status_team (user_id, status, team_type, team_term_id)
		) ENGINE=InnoDB {$charset};";

		$repair = self::drop_conflicting_indexes();
		if ( is_wp_error( $repair ) ) {
			self::record_failure( $repair );
			return $repair;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $claims_sql );
		$claims_error = (string) $wpdb->last_error;
		dbDelta( $invites_sql );
		$invites_error = (string) $wpdb->last_error;
		if ( '' !== $claims_error || '' !== $invites_error ) {
			$error = new \WP_Error(
				'venue_schema_dbdelta_failed',
				__( 'The venue schema could not be reconciled.', 'extrachill-events' ),
				array(
					'claims_error'  => $claims_error,
					'invites_error' => $invites_error,
				)
			);
			self::record_failure( $error );
			return $error;
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

	/** Refuse retirement unless every existing legacy onboarding table is empty. */
	private static function drop_empty_legacy_onboarding() {
		global $wpdb;
		$tables = array( $wpdb->prefix . 'ec_venue_claims', $wpdb->prefix . 'ec_venue_invitations' );
		$empty  = array();
		foreach ( $tables as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Installer inspection.
			if ( '' !== (string) $wpdb->last_error ) {
				return self::database_error( 'Could not inspect legacy onboarding storage.', $table );
			}
			if ( $found !== $table ) {
				continue;
			}
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB -- Fixed legacy identifier; never discard rows.
			if ( null === $count || 0 !== (int) $count ) {
				return new \WP_Error( 'team_onboarding_legacy_not_empty', __( 'Legacy onboarding storage must be empty before retirement.', 'extrachill-events' ), array( 'table' => $table ) );
			}
			$empty[] = $table;
		}
		foreach ( $empty as $table ) {
			if ( false === $wpdb->query( "DROP TABLE `{$table}`" ) ) { // phpcs:ignore WordPress.DB -- Only verified empty legacy tables.
				return self::database_error( 'Could not retire legacy onboarding storage.', $table );
			}
		}
		return true;
	}

	/** Install only when the stored schema version is stale. */
	public static function maybe_install() {
		if ( self::SCHEMA_VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
			return NetworkTeamMembersMigration::copy_legacy();
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

			self::claims_table()      => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'                  => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'public_id'           => $required( 'char(36)', false ),
					'team_type'           => $required( 'varchar(16)', false ),
					'team_term_id'        => $required( 'bigint unsigned', false ),
					'claimant_user_id'    => $required( 'bigint unsigned', false ),
					'status'              => $required( 'varchar(20)', false, array( 'default' => 'pending' ) ),
					'version'             => $required( 'bigint unsigned', false, array( 'default' => '1' ) ),
					'reviewed_by_user_id' => $required( 'bigint unsigned', true ),
					'created_at'          => $required( 'datetime', false ),
					'updated_at'          => $required( 'datetime', false ),
					'resolved_at'         => $required( 'datetime', true ),
				),
				'indexes' => array(
					'PRIMARY'              => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'public_id'            => array(
						'unique'  => true,
						'columns' => array( 'public_id' ),
					),
					'team_claimant'        => array(
						'unique'  => true,
						'columns' => array( 'team_type', 'team_term_id', 'claimant_user_id' ),
					),
					'status_created'       => array(
						'unique'  => false,
						'columns' => array( 'team_type', 'status', 'created_at' ),
					),
					'team_status'          => array(
						'unique'  => false,
						'columns' => array( 'team_type', 'team_term_id', 'status' ),
					),
					'claimant_status_team' => array(
						'unique'  => false,
						'columns' => array( 'claimant_user_id', 'status', 'team_type', 'team_term_id' ),
					),
				),
			),
			self::invitations_table() => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'                 => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'public_id'          => $required( 'char(36)', false ),
					'team_type'          => $required( 'varchar(16)', false ),
					'team_term_id'       => $required( 'bigint unsigned', false ),
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
					'team_user'         => array(
						'unique'  => true,
						'columns' => array( 'team_type', 'team_term_id', 'user_id' ),
					),
					'team_status'       => array(
						'unique'  => false,
						'columns' => array( 'team_type', 'team_term_id', 'status' ),
					),
					'status_expiration' => array(
						'unique'  => false,
						'columns' => array( 'team_type', 'status', 'expires_at' ),
					),
					'user_status_team'  => array(
						'unique'  => false,
						'columns' => array( 'user_id', 'status', 'team_type', 'team_term_id' ),
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
