<?php
/**
 * Verified promoter authority storage.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

/** Owns the small site-scoped promoter authority schema. */
final class PromoterAuthoritySchema {

	public const SCHEMA_VERSION = '2';
	public const VERSION_OPTION = 'extrachill_events_promoter_authority_schema_version';
	public const FAILURE_OPTION = 'extrachill_events_promoter_authority_schema_error';

	/** Return the current site's organization table. */
	public static function organizations_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_promoter_organizations';
	}

	/** Return the current site's membership table. */
	public static function memberships_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_promoter_members';
	}

	/** Return the current site's authority activity table. */
	public static function activity_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_promoter_authority_activity';
	}

	/** Return the current site's exact promoter/venue grant table. */
	public static function venue_grants_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_promoter_venue_grants';
	}

	/** Reconcile and verify all tables before publishing readiness. */
	public static function install() {
		global $wpdb;
		$lock_guard = null;
		if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof \mysqli ) {
			$lock_name = 'ec_promoter_authority_' . substr( hash( 'sha256', (string) DB_NAME . "\0" . $wpdb->prefix ), 0, 40 );
			$acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 30)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes this site schema installer.
			if ( 1 !== (int) $acquired ) {
				return new \WP_Error( 'promoter_authority_schema_lock_failed', __( 'The promoter authority schema installer could not acquire its site lock.', 'extrachill-events' ) );
			}
			$lock_guard = new class( $wpdb, $lock_name ) {
				/**
				 * Database connection holding the lock.
				 *
				 * @var \wpdb
				 */
				private $database;

				/**
				 * Exact advisory lock name.
				 *
				 * @var string
				 */
				private $name;

				/**
				 * Retain the lock owner through every install return path.
				 *
				 * @param \wpdb  $database Database connection.
				 * @param string $name     Exact advisory lock name.
				 */
				public function __construct( $database, string $name ) {
					$this->database = $database;
					$this->name     = $name;
				}
				/** Release the exact site-scoped advisory lock. */
				public function __destruct() {
					$this->database->get_var( $this->database->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the exact advisory lock.
				}
			};
		}

		$organizations    = self::organizations_table();
		$memberships      = self::memberships_table();
		$activity         = self::activity_table();
		$venue_grants     = self::venue_grants_table();
		$charset          = $wpdb->get_charset_collate();
		$organization_sql = "CREATE TABLE {$organizations} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			promoter_term_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			verified_by_user_id BIGINT UNSIGNED NOT NULL,
			verified_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			revoked_by_user_id BIGINT UNSIGNED NULL,
			revoked_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY promoter_term_id (promoter_term_id),
			KEY status_updated (status, updated_at)
		) ENGINE=InnoDB {$charset};";
		$membership_sql   = "CREATE TABLE {$memberships} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			promoter_term_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			is_owner TINYINT UNSIGNED NOT NULL DEFAULT '0',
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			created_by_user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			revoked_by_user_id BIGINT UNSIGNED NULL,
			revoked_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY promoter_user (promoter_term_id, user_id),
			KEY user_status_promoter (user_id, status, promoter_term_id),
			KEY promoter_status_owner (promoter_term_id, status, is_owner)
		) ENGINE=InnoDB {$charset};";
		$activity_sql     = "CREATE TABLE {$activity} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			promoter_term_id BIGINT UNSIGNED NOT NULL,
			event VARCHAR(48) NOT NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL,
			subject_user_id BIGINT UNSIGNED NULL,
			result_version BIGINT UNSIGNED NOT NULL,
			payload LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY promoter_created (promoter_term_id, created_at, id),
			KEY event_created (event, created_at)
		) ENGINE=InnoDB {$charset};";
		$venue_grants_sql = "CREATE TABLE {$venue_grants} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			promoter_term_id BIGINT UNSIGNED NOT NULL,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(48) NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			created_by_user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_by_user_id BIGINT UNSIGNED NOT NULL,
			updated_at DATETIME NOT NULL,
			revoked_by_user_id BIGINT UNSIGNED NULL,
			revoked_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY promoter_venue_action (promoter_term_id, venue_term_id, action),
			KEY promoter_status_action (promoter_term_id, status, action, venue_term_id),
			KEY venue_status_action (venue_term_id, status, action, promoter_term_id)
		) ENGINE=InnoDB {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $organization_sql );
		$organization_error = (string) $wpdb->last_error;
		dbDelta( $membership_sql );
		$membership_error = (string) $wpdb->last_error;
		dbDelta( $activity_sql );
		$activity_error = (string) $wpdb->last_error;
		dbDelta( $venue_grants_sql );
		$venue_grants_error = (string) $wpdb->last_error;
		if ( '' !== $organization_error || '' !== $membership_error || '' !== $activity_error || '' !== $venue_grants_error ) {
			$error = new \WP_Error( 'promoter_authority_schema_install_failed', __( 'The promoter authority schema could not be reconciled.', 'extrachill-events' ), compact( 'organization_error', 'membership_error', 'activity_error', 'venue_grants_error' ) );
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
		unset( $lock_guard );
		return true;
	}

	/** Install only when the published schema version is stale. */
	public static function maybe_install() {
		return self::is_ready() ? true : self::install();
	}

	/** Return the cheap request-time readiness signal. */
	public static function is_ready(): bool {
		return self::SCHEMA_VERSION === (string) get_option( self::VERSION_OPTION, '' );
	}

	/** Verify transactional storage, required columns, and exact uniqueness contracts. */
	public static function health() {
		global $wpdb;
		$contracts = array(
			self::organizations_table() => array(
				'columns' => array( 'id', 'promoter_term_id', 'status', 'version', 'verified_by_user_id', 'verified_at', 'updated_at', 'revoked_by_user_id', 'revoked_at' ),
				'unique'  => array(
					'PRIMARY'          => array( 'id' ),
					'promoter_term_id' => array( 'promoter_term_id' ),
				),
			),
			self::memberships_table()   => array(
				'columns' => array( 'id', 'promoter_term_id', 'user_id', 'is_owner', 'status', 'version', 'created_by_user_id', 'created_at', 'updated_at', 'revoked_by_user_id', 'revoked_at' ),
				'unique'  => array(
					'PRIMARY'       => array( 'id' ),
					'promoter_user' => array( 'promoter_term_id', 'user_id' ),
				),
			),
			self::activity_table()      => array(
				'columns' => array( 'id', 'promoter_term_id', 'event', 'actor_user_id', 'subject_user_id', 'result_version', 'payload', 'created_at' ),
				'unique'  => array( 'PRIMARY' => array( 'id' ) ),
			),
			self::venue_grants_table()  => array(
				'columns' => array( 'id', 'promoter_term_id', 'venue_term_id', 'action', 'status', 'version', 'created_by_user_id', 'created_at', 'updated_by_user_id', 'updated_at', 'revoked_by_user_id', 'revoked_at' ),
				'unique'  => array(
					'PRIMARY'               => array( 'id' ),
					'promoter_venue_action' => array( 'promoter_term_id', 'venue_term_id', 'action' ),
				),
			),
		);
		foreach ( $contracts as $table => $contract ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema health cannot use cached data.
			if ( $found !== $table || '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'promoter_authority_schema_table_missing', __( 'A promoter authority table is missing.', 'extrachill-events' ), array( 'table' => $table ) );
			}
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema health cannot use cached data.
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error(
					'promoter_authority_schema_inspection_failed',
					__( 'The promoter authority table engine could not be inspected.', 'extrachill-events' ),
					array(
						'table'          => $table,
						'database_error' => $wpdb->last_error,
					)
				);
			}
			if ( 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) ) ) {
				return new \WP_Error( 'promoter_authority_schema_engine_invalid', __( 'Promoter authority storage must be transactional.', 'extrachill-events' ), array( 'table' => $table ) );
			}
			$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error(
					'promoter_authority_schema_inspection_failed',
					__( 'Promoter authority columns could not be inspected.', 'extrachill-events' ),
					array(
						'table'          => $table,
						'database_error' => $wpdb->last_error,
					)
				);
			}
			$found_columns = array_column( (array) $columns, 'Field' );
			foreach ( $contract['columns'] as $column ) {
				if ( ! in_array( $column, $found_columns, true ) ) {
					return new \WP_Error(
						'promoter_authority_schema_column_missing',
						__( 'A required promoter authority column is missing.', 'extrachill-events' ),
						array(
							'table'  => $table,
							'column' => $column,
						)
					);
				}
			}
			$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error(
					'promoter_authority_schema_inspection_failed',
					__( 'Promoter authority indexes could not be inspected.', 'extrachill-events' ),
					array(
						'table'          => $table,
						'database_error' => $wpdb->last_error,
					)
				);
			}
			$unique = array();
			foreach ( (array) $indexes as $index ) {
				if ( 0 === (int) $index['Non_unique'] ) {
					$unique[ (string) $index['Key_name'] ][ (int) $index['Seq_in_index'] ] = (string) $index['Column_name'];
				}
			}
			foreach ( $unique as &$columns ) {
				ksort( $columns );
				$columns = array_values( $columns );
			}
			unset( $columns );
			foreach ( $contract['unique'] as $index => $expected_columns ) {
				if ( ! isset( $unique[ $index ] ) || $expected_columns !== $unique[ $index ] ) {
					return new \WP_Error(
						'promoter_authority_schema_index_invalid',
						__( 'A promoter authority uniqueness contract is missing or malformed.', 'extrachill-events' ),
						array(
							'table'            => $table,
							'index'            => $index,
							'expected_columns' => $expected_columns,
							'actual_columns'   => $unique[ $index ] ?? array(),
						)
					);
				}
			}
		}
		return true;
	}

	/**
	 * Record an actionable failure without advancing readiness.
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
