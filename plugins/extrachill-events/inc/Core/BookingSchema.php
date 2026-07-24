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

	public const SCHEMA_VERSION = '8';
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

	/** Get the booking communication state table for the current site. */
	public static function communication_state_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_booking_communication_state';
	}

	/** Get the booking attachments table for the current site. */
	public static function attachments_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_booking_attachments';
	}

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

	/** Get the booking holds table for the current site. */
	public static function holds_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_booking_holds';
	}

	/** Create or repair all tables, stamping the version only after verification. */
	public static function install() {
		global $wpdb;

		$bookings            = self::bookings_table();
		$activity            = self::activity_table();
		$communication_state = self::communication_state_table();
		$attachments         = self::attachments_table();
		$members             = self::memberships_table();
		$claims              = self::claims_table();
		$invites             = self::invitations_table();
		$audit               = self::onboarding_audit_table();
		$holds               = self::holds_table();
		$charset             = $wpdb->get_charset_collate();

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
			inquiry_idempotency_key VARCHAR(191) NULL,
			inquiry_request_hash CHAR(64) NULL,
			requested_space_key VARCHAR(64) NULL,
			space_key VARCHAR(64) NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'submitted',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			assignee_user_id BIGINT UNSIGNED NULL,
			requested_start_at DATETIME NULL,
			requested_end_at DATETIME NULL,
			performance_start_at DATETIME NULL,
			performance_end_at DATETIME NULL,
			intake_payload LONGTEXT NOT NULL,
			production_payload LONGTEXT NULL,
			deal_payload LONGTEXT NULL,
			confirmed_deal_payload LONGTEXT NULL,
			event_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY event_id (event_id),
			UNIQUE KEY venue_inquiry_idempotency (venue_term_id, inquiry_idempotency_key),
			KEY venue_status_created (venue_term_id, status, created_at),
			KEY venue_requested_start (venue_term_id, requested_start_at),
			KEY venue_performance_start (venue_term_id, performance_start_at),
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
			communication_intent_id BIGINT UNSIGNED NULL,
			is_communication TINYINT UNSIGNED NOT NULL DEFAULT '0',
			payload LONGTEXT NOT NULL,
			external_id VARCHAR(191) NULL,
			idempotency_key VARCHAR(191) NULL,
			occurred_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY booking_idempotency (booking_id, idempotency_key),
			KEY booking_occurred (booking_id, occurred_at, id),
			KEY booking_communication_occurred (booking_id, is_communication, occurred_at, id),
			KEY communication_intent_kind (communication_intent_id, kind),
			KEY kind_occurred (kind, occurred_at),
			KEY channel_external (channel, external_id)
		) ENGINE=InnoDB {$charset};";

		$communication_state_sql = "CREATE TABLE {$communication_state} (
			intent_id BIGINT UNSIGNED NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'requested',
			claim_stage VARCHAR(16) NULL,
			action_id BIGINT UNSIGNED NULL,
			updated_activity_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (intent_id),
			KEY booking_status_intent (booking_id, status, intent_id)
		) ENGINE=InnoDB {$charset};";

		$attachments_sql = "CREATE TABLE {$attachments} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			uploader_type VARCHAR(20) NOT NULL,
			uploader_user_id BIGINT UNSIGNED NULL,
			uploader_reference VARCHAR(191) NULL,
			artist_term_id BIGINT UNSIGNED NULL,
			artist_profile_id BIGINT UNSIGNED NULL,
			purpose VARCHAR(32) NOT NULL,
			original_filename VARCHAR(255) NOT NULL,
			mime_type VARCHAR(127) NOT NULL,
			byte_size BIGINT UNSIGNED NOT NULL,
			content_hash CHAR(64) NOT NULL,
			storage_reference VARCHAR(191) NOT NULL,
			state VARCHAR(20) NOT NULL DEFAULT 'active',
			idempotency_key VARCHAR(191) NOT NULL,
			request_hash CHAR(64) NOT NULL,
			replaces_attachment_id BIGINT UNSIGNED NULL,
			retired_at DATETIME NULL,
			purged_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY booking_idempotency (booking_id, idempotency_key),
			KEY booking_state_created (booking_id, state, created_at),
			KEY storage_reference_state (storage_reference, state),
			KEY artist_term_purpose (artist_term_id, purpose),
			KEY artist_profile_purpose (artist_profile_id, purpose),
			KEY state_retired (state, retired_at)
		) ENGINE=InnoDB {$charset};";

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

		$holds_sql = "CREATE TABLE {$holds} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_id BIGINT UNSIGNED NOT NULL,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			space_key VARCHAR(64) NOT NULL,
			start_at DATETIME NOT NULL,
			end_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			created_by_user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			released_at DATETIME NULL,
			released_by_user_id BIGINT UNSIGNED NULL,
			release_reason VARCHAR(255) NULL,
			expired_at DATETIME NULL,
			converted_at DATETIME NULL,
			converted_by_user_id BIGINT UNSIGNED NULL,
			PRIMARY KEY (id),
			KEY venue_space_overlap (venue_term_id, space_key, status, start_at, end_at),
			KEY booking_status (booking_id, status),
			KEY status_expiration (status, expires_at)
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
		dbDelta( $communication_state_sql );
		$communication_state_error = (string) $wpdb->last_error;
		dbDelta( $attachments_sql );
		$attachments_error = (string) $wpdb->last_error;
		dbDelta( $members_sql );
		$members_error = (string) $wpdb->last_error;
		dbDelta( $claims_sql );
		$claims_error = (string) $wpdb->last_error;
		dbDelta( $invites_sql );
		$invites_error = (string) $wpdb->last_error;
		dbDelta( $audit_sql );
		$audit_error = (string) $wpdb->last_error;
		dbDelta( $holds_sql );
		$holds_error = (string) $wpdb->last_error;
		if ( '' !== $bookings_error || '' !== $activity_error || '' !== $communication_state_error || '' !== $attachments_error || '' !== $members_error || '' !== $claims_error || '' !== $invites_error || '' !== $audit_error || '' !== $holds_error ) {
			$error = new \WP_Error(
				'booking_schema_dbdelta_failed',
				__( 'The booking schema could not be reconciled.', 'extrachill-events' ),
				array(
					'bookings_error'            => $bookings_error,
					'activity_error'            => $activity_error,
					'communication_state_error' => $communication_state_error,
					'attachments_error'         => $attachments_error,
					'members_error'             => $members_error,
					'claims_error'              => $claims_error,
					'invites_error'             => $invites_error,
					'audit_error'               => $audit_error,
					'holds_error'               => $holds_error,
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
				return self::database_error( __( 'Could not inspect the booking tables.', 'extrachill-events' ), $table );
			}
			if ( $found !== $table ) {
				return new \WP_Error( 'booking_schema_table_missing', __( 'A required booking table is missing.', 'extrachill-events' ), array( 'table' => $table ) );
			}
			if ( isset( $contract['engine'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Installation health cannot be cached.
				$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
				if ( '' !== (string) $wpdb->last_error ) {
					return self::database_error( __( 'Could not inspect the booking table engine.', 'extrachill-events' ), $table );
				}
				$engine = strtolower( (string) ( $status['Engine'] ?? '' ) );
				if ( $contract['engine'] !== $engine ) {
					return new \WP_Error(
						'booking_schema_engine_invalid',
						__( 'A booking table does not use the required transactional engine.', 'extrachill-events' ),
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
				return self::database_error( __( 'Could not inspect the booking table engine for repair.', 'extrachill-events' ), $table );
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
					'booking_schema_engine_repair_failed',
					__( 'A booking table could not be converted to its required transactional engine.', 'extrachill-events' ),
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
		return array(
			self::bookings_table()            => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'                      => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'public_id'               => $required( 'char(36)', false ),
					'venue_term_id'           => $required( 'bigint unsigned', false ),
					'artist_term_id'          => $required( 'bigint unsigned', true ),
					'artist_profile_id'       => $required( 'bigint unsigned', true ),
					'artist_name'             => $required( 'varchar(255)', false ),
					'submitter_user_id'       => $required( 'bigint unsigned', true ),
					'contact_name'            => $required( 'varchar(255)', true ),
					'contact_email'           => $required( 'varchar(255)', true ),
					'contact_phone'           => $required( 'varchar(64)', true ),
					'inquiry_idempotency_key' => $required( 'varchar(191)', true ),
					'inquiry_request_hash'    => $required( 'char(64)', true ),
					'requested_space_key'     => $required( 'varchar(64)', true ),
					'space_key'               => $required( 'varchar(64)', true ),
					'status'                  => $required( 'varchar(32)', false, array( 'default' => 'submitted' ) ),
					'version'                 => $required( 'bigint unsigned', false, array( 'default' => '1' ) ),
					'assignee_user_id'        => $required( 'bigint unsigned', true ),
					'requested_start_at'      => $required( 'datetime', true ),
					'requested_end_at'        => $required( 'datetime', true ),
					'performance_start_at'    => $required( 'datetime', true ),
					'performance_end_at'      => $required( 'datetime', true ),
					'intake_payload'          => $required( 'longtext', false ),
					'production_payload'      => $required( 'longtext', true ),
					'deal_payload'            => $required( 'longtext', true ),
					'confirmed_deal_payload'  => $required( 'longtext', true ),
					'event_id'                => $required( 'bigint unsigned', true ),
					'created_at'              => $required( 'datetime', false ),
					'updated_at'              => $required( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'                   => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'public_id'                 => array(
						'unique'  => true,
						'columns' => array( 'public_id' ),
					),
					'event_id'                  => array(
						'unique'  => true,
						'columns' => array( 'event_id' ),
					),
					'venue_inquiry_idempotency' => array(
						'unique'  => true,
						'columns' => array( 'venue_term_id', 'inquiry_idempotency_key' ),
					),
					'venue_status_created'      => array(
						'unique'  => false,
						'columns' => array( 'venue_term_id', 'status', 'created_at' ),
					),
					'venue_requested_start'     => array(
						'unique'  => false,
						'columns' => array( 'venue_term_id', 'requested_start_at' ),
					),
					'venue_performance_start'   => array(
						'unique'  => false,
						'columns' => array( 'venue_term_id', 'performance_start_at' ),
					),
					'artist_term_created'       => array(
						'unique'  => false,
						'columns' => array( 'artist_term_id', 'created_at' ),
					),
					'artist_profile_created'    => array(
						'unique'  => false,
						'columns' => array( 'artist_profile_id', 'created_at' ),
					),
					'assignee_status'           => array(
						'unique'  => false,
						'columns' => array( 'assignee_user_id', 'status' ),
					),
					'status_updated'            => array(
						'unique'  => false,
						'columns' => array( 'status', 'updated_at' ),
					),
				),
			),
			self::activity_table()            => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'                      => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'booking_id'              => $required( 'bigint unsigned', false ),
					'kind'                    => $required( 'varchar(64)', false ),
					'actor_type'              => $required( 'varchar(32)', false, array( 'default' => 'system' ) ),
					'actor_id'                => $required( 'bigint unsigned', true ),
					'direction'               => $required( 'varchar(16)', true ),
					'channel'                 => $required( 'varchar(32)', true ),
					'communication_intent_id' => $required( 'bigint unsigned', true ),
					'is_communication'        => $required( 'tinyint unsigned', false, array( 'default' => '0' ) ),
					'payload'                 => $required( 'longtext', false ),
					'external_id'             => $required( 'varchar(191)', true ),
					'idempotency_key'         => $required( 'varchar(191)', true ),
					'occurred_at'             => $required( 'datetime', false ),
					'created_at'              => $required( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'                        => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'booking_idempotency'            => array(
						'unique'  => true,
						'columns' => array( 'booking_id', 'idempotency_key' ),
					),
					'booking_occurred'               => array(
						'unique'  => false,
						'columns' => array( 'booking_id', 'occurred_at', 'id' ),
					),
					'booking_communication_occurred' => array(
						'unique'  => false,
						'columns' => array( 'booking_id', 'is_communication', 'occurred_at', 'id' ),
					),
					'communication_intent_kind'      => array(
						'unique'  => false,
						'columns' => array( 'communication_intent_id', 'kind' ),
					),
					'kind_occurred'                  => array(
						'unique'  => false,
						'columns' => array( 'kind', 'occurred_at' ),
					),
					'channel_external'               => array(
						'unique'  => false,
						'columns' => array( 'channel', 'external_id' ),
					),
				),
			),
			self::communication_state_table() => array(
				'engine'  => 'innodb',
				'columns' => array(
					'intent_id'           => $required( 'bigint unsigned', false ),
					'booking_id'          => $required( 'bigint unsigned', false ),
					'status'              => $required( 'varchar(32)', false, array( 'default' => 'requested' ) ),
					'claim_stage'         => $required( 'varchar(16)', true ),
					'action_id'           => $required( 'bigint unsigned', true ),
					'updated_activity_id' => $required( 'bigint unsigned', false ),
					'created_at'          => $required( 'datetime', false ),
					'updated_at'          => $required( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'               => array(
						'unique'  => true,
						'columns' => array( 'intent_id' ),
					),
					'booking_status_intent' => array(
						'unique'  => false,
						'columns' => array( 'booking_id', 'status', 'intent_id' ),
					),
				),
			),
			self::attachments_table()         => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'                     => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'public_id'              => $required( 'char(36)', false ),
					'booking_id'             => $required( 'bigint unsigned', false ),
					'uploader_type'          => $required( 'varchar(20)', false ),
					'uploader_user_id'       => $required( 'bigint unsigned', true ),
					'uploader_reference'     => $required( 'varchar(191)', true ),
					'artist_term_id'         => $required( 'bigint unsigned', true ),
					'artist_profile_id'      => $required( 'bigint unsigned', true ),
					'purpose'                => $required( 'varchar(32)', false ),
					'original_filename'      => $required( 'varchar(255)', false ),
					'mime_type'              => $required( 'varchar(127)', false ),
					'byte_size'              => $required( 'bigint unsigned', false ),
					'content_hash'           => $required( 'char(64)', false ),
					'storage_reference'      => $required( 'varchar(191)', false ),
					'state'                  => $required( 'varchar(20)', false, array( 'default' => 'active' ) ),
					'idempotency_key'        => $required( 'varchar(191)', false ),
					'request_hash'           => $required( 'char(64)', false ),
					'replaces_attachment_id' => $required( 'bigint unsigned', true ),
					'retired_at'             => $required( 'datetime', true ),
					'purged_at'              => $required( 'datetime', true ),
					'created_at'             => $required( 'datetime', false ),
					'updated_at'             => $required( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'                 => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'public_id'               => array(
						'unique'  => true,
						'columns' => array( 'public_id' ),
					),
					'booking_idempotency'     => array(
						'unique'  => true,
						'columns' => array( 'booking_id', 'idempotency_key' ),
					),
					'booking_state_created'   => array(
						'unique'  => false,
						'columns' => array( 'booking_id', 'state', 'created_at' ),
					),
					'storage_reference_state' => array(
						'unique'  => false,
						'columns' => array( 'storage_reference', 'state' ),
					),
					'artist_term_purpose'     => array(
						'unique'  => false,
						'columns' => array( 'artist_term_id', 'purpose' ),
					),
					'artist_profile_purpose'  => array(
						'unique'  => false,
						'columns' => array( 'artist_profile_id', 'purpose' ),
					),
					'state_retired'           => array(
						'unique'  => false,
						'columns' => array( 'state', 'retired_at' ),
					),
				),
			),
			self::memberships_table()         => array(
				'engine'  => 'innodb',
				'columns' => array(
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
				'indexes' => array(
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
			self::claims_table()              => array(
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
			self::invitations_table()         => array(
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
			self::onboarding_audit_table()    => array(
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
			self::holds_table()               => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'                   => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'booking_id'           => $required( 'bigint unsigned', false ),
					'venue_term_id'        => $required( 'bigint unsigned', false ),
					'space_key'            => $required( 'varchar(64)', false ),
					'start_at'             => $required( 'datetime', false ),
					'end_at'               => $required( 'datetime', false ),
					'expires_at'           => $required( 'datetime', false ),
					'status'               => $required( 'varchar(16)', false, array( 'default' => 'active' ) ),
					'version'              => $required( 'bigint unsigned', false, array( 'default' => '1' ) ),
					'created_by_user_id'   => $required( 'bigint unsigned', false ),
					'created_at'           => $required( 'datetime', false ),
					'updated_at'           => $required( 'datetime', false ),
					'released_at'          => $required( 'datetime', true ),
					'released_by_user_id'  => $required( 'bigint unsigned', true ),
					'release_reason'       => $required( 'varchar(255)', true ),
					'expired_at'           => $required( 'datetime', true ),
					'converted_at'         => $required( 'datetime', true ),
					'converted_by_user_id' => $required( 'bigint unsigned', true ),
				),
				'indexes' => array(
					'PRIMARY'             => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'venue_space_overlap' => array(
						'unique'  => false,
						'columns' => array( 'venue_term_id', 'space_key', 'status', 'start_at', 'end_at' ),
					),
					'booking_status'      => array(
						'unique'  => false,
						'columns' => array( 'booking_id', 'status' ),
					),
					'status_expiration'   => array(
						'unique'  => false,
						'columns' => array( 'status', 'expires_at' ),
					),
				),
			),
		);
	}

	/** Normalize one SHOW COLUMNS row to the declared contract shape. */
	private static function normalize_column( array $column ): array {
		$type = preg_replace( '/\(\d+\)(?=\s+unsigned)/', '', strtolower( trim( (string) ( $column['Type'] ?? '' ) ) ) );
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
