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

	public const SCHEMA_VERSION = '15';
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

	/** Get the private attachment delivery correlation table for the current site. */
	public static function attachment_deliveries_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_booking_attachment_deliveries';
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

	/** Get the append-only booking sales reports table. */
	public static function sales_reports_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_booking_sales_reports';
	}

	/** Get the immutable provider-neutral ticket source identities table. */
	public static function ticket_sources_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_booking_ticket_sources';
	}

	/** Get the append-only ticket reconciliation resolutions table. */
	public static function sales_resolutions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_booking_sales_resolutions';
	}

	/** Get the frozen booking settlements table. */
	public static function settlements_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_booking_settlements';
	}

	/** Get the immutable complete show-settlement revisions table. */
	public static function show_settlements_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_booking_show_settlements';
	}

	/** Get the append-only show-settlement lifecycle table. */
	public static function show_settlement_actions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_booking_show_settlement_actions';
	}

	/** Create or repair all tables, stamping the version only after verification. */
	public static function install() {
		global $wpdb;
		$schema_lock = null;
		$lock_guard  = null;
		if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof \mysqli ) {
			$schema_lock = 'ec_booking_schema_' . substr( hash( 'sha256', (string) DB_NAME . "\0" . $wpdb->prefix ), 0, 40 );
			$acquired    = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 30)', $schema_lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes concurrent installers for this site prefix.
			if ( 1 !== (int) $acquired ) {
				return new \WP_Error( 'booking_schema_lock_failed', __( 'The booking schema installer could not acquire its site lock.', 'extrachill-events' ) );
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

		$bookings            = self::bookings_table();
		$activity            = self::activity_table();
		$communication_state = self::communication_state_table();
		$attachments         = self::attachments_table();
		$deliveries          = self::attachment_deliveries_table();
		$members             = self::memberships_table();
		$claims              = self::claims_table();
		$invites             = self::invitations_table();
		$audit               = self::onboarding_audit_table();
		$holds               = self::holds_table();
		$ticket_sources      = self::ticket_sources_table();
		$sales_reports       = self::sales_reports_table();
		$sales_resolutions   = self::sales_resolutions_table();
		$settlements         = self::settlements_table();
		$show_settlements    = self::show_settlements_table();
		$show_actions        = self::show_settlement_actions_table();
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
			admission_owner_token CHAR(36) NULL,
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

		$deliveries_sql = "CREATE TABLE {$deliveries} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			correlation_id CHAR(36) NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			attachment_id BIGINT UNSIGNED NOT NULL,
			actor_id BIGINT UNSIGNED NOT NULL,
			expected_bytes BIGINT UNSIGNED NOT NULL,
			state VARCHAR(16) NOT NULL DEFAULT 'issued',
			outcome VARCHAR(16) NULL,
			bytes_sent BIGINT UNSIGNED NULL,
			issued_at DATETIME NOT NULL,
			consumed_at DATETIME NULL,
			terminal_at DATETIME NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY correlation_id (correlation_id),
			KEY booking_issued (booking_id, issued_at, id),
			KEY attachment_actor (attachment_id, actor_id),
			KEY state_updated (state, updated_at),
			KEY terminal_retention (state, terminal_at)
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

		$ticket_sources_sql = "CREATE TABLE {$ticket_sources} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			event_id BIGINT UNSIGNED NOT NULL,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			provider VARCHAR(64) NOT NULL,
			source_key VARCHAR(191) NOT NULL,
			source_key_hash CHAR(64) NOT NULL,
			canonical_url LONGTEXT NOT NULL,
			url_hash CHAR(64) NOT NULL,
			request_hash CHAR(64) NOT NULL,
			created_by_user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY booking_provider_source (booking_id, provider, source_key_hash),
			KEY event_provider (event_id, provider),
			KEY venue_created (venue_term_id, created_at, id)
		) ENGINE=InnoDB {$charset};";

		$sales_reports_sql = "CREATE TABLE {$sales_reports} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_id BIGINT UNSIGNED NOT NULL,
			event_id BIGINT UNSIGNED NOT NULL,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			ticket_source_id BIGINT UNSIGNED NULL,
			evidence_attachment_id BIGINT UNSIGNED NULL,
			provider VARCHAR(64) NOT NULL,
			external_report_id VARCHAR(191) NOT NULL,
			external_report_id_hash CHAR(64) NOT NULL,
			source_type VARCHAR(32) NOT NULL,
			provenance_version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			ticket_source_request_hash CHAR(64) NULL,
			evidence_attachment_request_hash CHAR(64) NULL,
			evidence_content_hash CHAR(64) NULL,
			evidence_byte_size BIGINT UNSIGNED NULL,
			period_start DATETIME NOT NULL,
			period_end DATETIME NOT NULL,
			tickets_sold BIGINT NOT NULL,
			tickets_refunded BIGINT NOT NULL,
			gross_minor BIGINT NOT NULL,
			fees_minor BIGINT NOT NULL,
			tax_minor BIGINT NOT NULL,
			refunds_minor BIGINT NOT NULL,
			net_minor BIGINT NOT NULL,
			currency CHAR(3) NOT NULL,
			corrects_report_id BIGINT UNSIGNED NULL,
			source_payload LONGTEXT NOT NULL,
			request_hash CHAR(64) NOT NULL,
			created_by_user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY provider_external_report (booking_id, provider, external_report_id_hash),
			KEY booking_created (booking_id, created_at, id),
			KEY booking_currency_id (booking_id, currency, id),
			KEY ticket_source_id (ticket_source_id),
			KEY evidence_attachment_id (evidence_attachment_id),
			KEY event_provider_period (event_id, provider, period_start, period_end),
			KEY corrects_report (corrects_report_id)
		) ENGINE=InnoDB {$charset};";

		$sales_resolutions_sql = "CREATE TABLE {$sales_resolutions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			report_id BIGINT UNSIGNED NOT NULL,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			version BIGINT UNSIGNED NOT NULL,
			decision VARCHAR(16) NOT NULL,
			ticket_source_id BIGINT UNSIGNED NULL,
			supersedes_resolution_id BIGINT UNSIGNED NULL,
			reason VARCHAR(1000) NOT NULL,
			request_hash CHAR(64) NOT NULL,
			created_by_user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY report_version (report_id, version),
			KEY booking_report_version (booking_id, report_id, version),
			KEY venue_created (venue_term_id, created_at, id),
			KEY ticket_source_id (ticket_source_id),
			KEY supersedes_resolution (supersedes_resolution_id)
		) ENGINE=InnoDB {$charset};";

		$settlements_sql = "CREATE TABLE {$settlements} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_id BIGINT UNSIGNED NOT NULL,
			event_id BIGINT UNSIGNED NOT NULL,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'finalized',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			booking_version BIGINT UNSIGNED NOT NULL,
			basis VARCHAR(32) NOT NULL,
			basis_points BIGINT UNSIGNED NOT NULL,
			currency CHAR(3) NOT NULL,
			formula_version BIGINT UNSIGNED NOT NULL,
			included_report_ids LONGTEXT NOT NULL,
			evidence_hash CHAR(64) NOT NULL,
			integrity_hash CHAR(64) NOT NULL,
			basis_amount_minor BIGINT NOT NULL,
			adjustment_minor BIGINT NOT NULL,
			amount_due_minor BIGINT NOT NULL,
			finalized_by_user_id BIGINT UNSIGNED NOT NULL,
			finalized_at DATETIME NOT NULL,
			paid_by_user_id BIGINT UNSIGNED NULL,
			paid_at DATETIME NULL,
			payment_reference VARCHAR(191) NULL,
			voided_by_user_id BIGINT UNSIGNED NULL,
			voided_at DATETIME NULL,
			void_reason VARCHAR(1000) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY booking_id (booking_id),
			KEY venue_status_finalized (venue_term_id, status, finalized_at),
			KEY event_id (event_id),
			KEY status_updated (status, updated_at)
		) ENGINE=InnoDB {$charset};";

		$show_settlements_sql = "CREATE TABLE {$show_settlements} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			event_id BIGINT UNSIGNED NOT NULL,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			revision BIGINT UNSIGNED NOT NULL,
			corrects_revision_id BIGINT UNSIGNED NULL,
			commission_settlement_id BIGINT UNSIGNED NOT NULL,
			commission_integrity_hash CHAR(64) NOT NULL,
			currency CHAR(3) NOT NULL,
			formula_version BIGINT UNSIGNED NOT NULL,
			terms_payload LONGTEXT NOT NULL,
			evidence_payload LONGTEXT NOT NULL,
			calculation_payload LONGTEXT NOT NULL,
			request_hash CHAR(64) NOT NULL,
			integrity_hash CHAR(64) NOT NULL,
			idempotency_key VARCHAR(191) NOT NULL,
			created_by_user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY booking_revision (booking_id, revision),
			UNIQUE KEY booking_idempotency (booking_id, idempotency_key),
			KEY venue_created (venue_term_id, created_at, id),
			KEY event_id (event_id),
			KEY corrects_revision (corrects_revision_id),
			KEY commission_settlement (commission_settlement_id)
		) ENGINE=InnoDB {$charset};";

		$show_actions_sql = "CREATE TABLE {$show_actions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			booking_id BIGINT UNSIGNED NOT NULL,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			show_settlement_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(24) NOT NULL,
			expected_version BIGINT UNSIGNED NOT NULL,
			payload LONGTEXT NOT NULL,
			request_hash CHAR(64) NOT NULL,
			integrity_hash CHAR(64) NOT NULL,
			idempotency_key VARCHAR(191) NOT NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY revision_version (show_settlement_id, expected_version),
			UNIQUE KEY booking_idempotency (booking_id, idempotency_key),
			KEY booking_created (booking_id, created_at, id),
			KEY venue_action_created (venue_term_id, action, created_at),
			KEY settlement_created (show_settlement_id, created_at, id)
		) ENGINE=InnoDB {$charset};";

		$migration = self::migrate_v13_ticket_provenance();
		if ( is_wp_error( $migration ) ) {
			self::record_failure( $migration );
			return $migration;
		}

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
		dbDelta( $deliveries_sql );
		$deliveries_error = (string) $wpdb->last_error;
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
		dbDelta( $ticket_sources_sql );
		$ticket_sources_error = (string) $wpdb->last_error;
		dbDelta( $sales_reports_sql );
		$sales_reports_error = (string) $wpdb->last_error;
		dbDelta( $sales_resolutions_sql );
		$sales_resolutions_error = (string) $wpdb->last_error;
		dbDelta( $settlements_sql );
		$settlements_error = (string) $wpdb->last_error;
		dbDelta( $show_settlements_sql );
		$show_settlements_error = (string) $wpdb->last_error;
		dbDelta( $show_actions_sql );
		$show_actions_error = (string) $wpdb->last_error;
		if ( '' !== $bookings_error || '' !== $activity_error || '' !== $communication_state_error || '' !== $attachments_error || '' !== $deliveries_error || '' !== $members_error || '' !== $claims_error || '' !== $invites_error || '' !== $audit_error || '' !== $holds_error || '' !== $ticket_sources_error || '' !== $sales_reports_error || '' !== $sales_resolutions_error || '' !== $settlements_error || '' !== $show_settlements_error || '' !== $show_actions_error ) {
			$error = new \WP_Error(
				'booking_schema_dbdelta_failed',
				__( 'The booking schema could not be reconciled.', 'extrachill-events' ),
				array(
					'bookings_error'            => $bookings_error,
					'activity_error'            => $activity_error,
					'communication_state_error' => $communication_state_error,
					'attachments_error'         => $attachments_error,
					'deliveries_error'          => $deliveries_error,
					'members_error'             => $members_error,
					'claims_error'              => $claims_error,
					'invites_error'             => $invites_error,
					'audit_error'               => $audit_error,
					'holds_error'               => $holds_error,
					'ticket_sources_error'      => $ticket_sources_error,
					'sales_reports_error'       => $sales_reports_error,
					'sales_resolutions_error'   => $sales_resolutions_error,
					'settlements_error'         => $settlements_error,
					'show_settlements_error'    => $show_settlements_error,
					'show_actions_error'        => $show_actions_error,
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

	/** Add and backfill v14 identity/provenance storage before unique-index repair. */
	private static function migrate_v13_ticket_provenance() {
		global $wpdb;

		if ( ! isset( $wpdb->dbh ) || ! $wpdb->dbh instanceof \mysqli || version_compare( (string) get_option( self::VERSION_OPTION, '0' ), '14', '>=' ) ) {
			return true;
		}
		$tables = array(
			self::ticket_sources_table() => array(
				'columns'  => array( 'source_key_hash CHAR(64) NULL' ),
				'backfill' => 'source_key_hash = SHA2(source_key, 256)',
				'where'    => "source_key_hash IS NULL OR source_key_hash = ''",
				'final'    => 'source_key_hash CHAR(64) NOT NULL',
			),
			self::sales_reports_table()  => array(
				'columns'  => array(
					'external_report_id_hash CHAR(64) NULL',
					"provenance_version BIGINT UNSIGNED NOT NULL DEFAULT '1'",
					'ticket_source_request_hash CHAR(64) NULL',
					'evidence_attachment_request_hash CHAR(64) NULL',
					'evidence_content_hash CHAR(64) NULL',
					'evidence_byte_size BIGINT UNSIGNED NULL',
				),
				'backfill' => 'external_report_id_hash = SHA2(external_report_id, 256)',
				'where'    => "external_report_id_hash IS NULL OR external_report_id_hash = ''",
				'final'    => 'external_report_id_hash CHAR(64) NOT NULL',
			),
		);
		foreach ( $tables as $table => $migration ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit private schema migration.
			if ( $found !== $table ) {
				continue;
			}
			$found_columns = array_column( (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ), 'Field' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit private schema migration.
			foreach ( $migration['columns'] as $definition ) {
				$name = strtok( $definition, ' ' );
				if ( in_array( $name, $found_columns, true ) ) {
					continue;
				}
				if ( false === $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$definition}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixed internal migration definitions.
					return self::database_error( __( 'Could not add ticket provenance storage.', 'extrachill-events' ), $table );
				}
			}
			if ( false === $wpdb->query( "UPDATE `{$table}` SET {$migration['backfill']} WHERE {$migration['where']}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deterministic in-place identity hash backfill.
				return self::database_error( __( 'Could not backfill lossless ticket identity hashes.', 'extrachill-events' ), $table );
			}
			if ( false === $wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN {$migration['final']}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Makes the completed identity migration mandatory.
				return self::database_error( __( 'Could not finalize ticket identity hash storage.', 'extrachill-events' ), $table );
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
			self::bookings_table()                => array(
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
					'admission_owner_token'   => $required( 'char(36)', true ),
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
			self::activity_table()                => array(
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
			self::communication_state_table()     => array(
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
			self::attachments_table()             => array(
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
			self::attachment_deliveries_table()   => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'             => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'correlation_id' => $required( 'char(36)', false ),
					'booking_id'     => $required( 'bigint unsigned', false ),
					'attachment_id'  => $required( 'bigint unsigned', false ),
					'actor_id'       => $required( 'bigint unsigned', false ),
					'expected_bytes' => $required( 'bigint unsigned', false ),
					'state'          => $required( 'varchar(16)', false, array( 'default' => 'issued' ) ),
					'outcome'        => $required( 'varchar(16)', true ),
					'bytes_sent'     => $required( 'bigint unsigned', true ),
					'issued_at'      => $required( 'datetime', false ),
					'consumed_at'    => $required( 'datetime', true ),
					'terminal_at'    => $required( 'datetime', true ),
					'updated_at'     => $required( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'            => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'correlation_id'     => array(
						'unique'  => true,
						'columns' => array( 'correlation_id' ),
					),
					'booking_issued'     => array(
						'unique'  => false,
						'columns' => array( 'booking_id', 'issued_at', 'id' ),
					),
					'attachment_actor'   => array(
						'unique'  => false,
						'columns' => array( 'attachment_id', 'actor_id' ),
					),
					'state_updated'      => array(
						'unique'  => false,
						'columns' => array( 'state', 'updated_at' ),
					),
					'terminal_retention' => array(
						'unique'  => false,
						'columns' => array( 'state', 'terminal_at' ),
					),
				),
			),
			self::memberships_table()             => array(
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
			self::claims_table()                  => array(
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
			self::invitations_table()             => array(
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
			self::onboarding_audit_table()        => array(
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
			self::holds_table()                   => array(
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
			self::ticket_sources_table()          => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'                 => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'public_id'          => $required( 'char(36)', false ),
					'booking_id'         => $required( 'bigint unsigned', false ),
					'event_id'           => $required( 'bigint unsigned', false ),
					'venue_term_id'      => $required( 'bigint unsigned', false ),
					'provider'           => $required( 'varchar(64)', false ),
					'source_key'         => $required( 'varchar(191)', false ),
					'source_key_hash'    => $required( 'char(64)', false ),
					'canonical_url'      => $required( 'longtext', false ),
					'url_hash'           => $required( 'char(64)', false ),
					'request_hash'       => $required( 'char(64)', false ),
					'created_by_user_id' => $required( 'bigint unsigned', false ),
					'created_at'         => $required( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'                 => $index( true, 'id' ),
					'public_id'               => $index( true, 'public_id' ),
					'booking_provider_source' => $index( true, 'booking_id', 'provider', 'source_key_hash' ),
					'event_provider'          => $index( false, 'event_id', 'provider' ),
					'venue_created'           => $index( false, 'venue_term_id', 'created_at', 'id' ),
				),
			),
			self::sales_reports_table()           => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'                               => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'booking_id'                       => $required( 'bigint unsigned', false ),
					'event_id'                         => $required( 'bigint unsigned', false ),
					'venue_term_id'                    => $required( 'bigint unsigned', false ),
					'ticket_source_id'                 => $required( 'bigint unsigned', true ),
					'evidence_attachment_id'           => $required( 'bigint unsigned', true ),
					'provider'                         => $required( 'varchar(64)', false ),
					'external_report_id'               => $required( 'varchar(191)', false ),
					'external_report_id_hash'          => $required( 'char(64)', false ),
					'source_type'                      => $required( 'varchar(32)', false ),
					'provenance_version'               => $required( 'bigint unsigned', false, array( 'default' => '1' ) ),
					'ticket_source_request_hash'       => $required( 'char(64)', true ),
					'evidence_attachment_request_hash' => $required( 'char(64)', true ),
					'evidence_content_hash'            => $required( 'char(64)', true ),
					'evidence_byte_size'               => $required( 'bigint unsigned', true ),
					'period_start'                     => $required( 'datetime', false ),
					'period_end'                       => $required( 'datetime', false ),
					'tickets_sold'                     => $required( 'bigint', false ),
					'tickets_refunded'                 => $required( 'bigint', false ),
					'gross_minor'                      => $required( 'bigint', false ),
					'fees_minor'                       => $required( 'bigint', false ),
					'tax_minor'                        => $required( 'bigint', false ),
					'refunds_minor'                    => $required( 'bigint', false ),
					'net_minor'                        => $required( 'bigint', false ),
					'currency'                         => $required( 'char(3)', false ),
					'corrects_report_id'               => $required( 'bigint unsigned', true ),
					'source_payload'                   => $required( 'longtext', false ),
					'request_hash'                     => $required( 'char(64)', false ),
					'created_by_user_id'               => $required( 'bigint unsigned', false ),
					'created_at'                       => $required( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'                  => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'provider_external_report' => array(
						'unique'  => true,
						'columns' => array( 'booking_id', 'provider', 'external_report_id_hash' ),
					),
					'booking_created'          => array(
						'unique'  => false,
						'columns' => array( 'booking_id', 'created_at', 'id' ),
					),
					'booking_currency_id'      => array(
						'unique'  => false,
						'columns' => array( 'booking_id', 'currency', 'id' ),
					),
					'ticket_source_id'         => $index( false, 'ticket_source_id' ),
					'evidence_attachment_id'   => $index( false, 'evidence_attachment_id' ),
					'event_provider_period'    => array(
						'unique'  => false,
						'columns' => array( 'event_id', 'provider', 'period_start', 'period_end' ),
					),
					'corrects_report'          => array(
						'unique'  => false,
						'columns' => array( 'corrects_report_id' ),
					),
				),
			),
			self::sales_resolutions_table()       => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'                       => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'public_id'                => $required( 'char(36)', false ),
					'booking_id'               => $required( 'bigint unsigned', false ),
					'report_id'                => $required( 'bigint unsigned', false ),
					'venue_term_id'            => $required( 'bigint unsigned', false ),
					'version'                  => $required( 'bigint unsigned', false ),
					'decision'                 => $required( 'varchar(16)', false ),
					'ticket_source_id'         => $required( 'bigint unsigned', true ),
					'supersedes_resolution_id' => $required( 'bigint unsigned', true ),
					'reason'                   => $required( 'varchar(1000)', false ),
					'request_hash'             => $required( 'char(64)', false ),
					'created_by_user_id'       => $required( 'bigint unsigned', false ),
					'created_at'               => $required( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'                => $index( true, 'id' ),
					'public_id'              => $index( true, 'public_id' ),
					'report_version'         => $index( true, 'report_id', 'version' ),
					'booking_report_version' => $index( false, 'booking_id', 'report_id', 'version' ),
					'venue_created'          => $index( false, 'venue_term_id', 'created_at', 'id' ),
					'ticket_source_id'       => $index( false, 'ticket_source_id' ),
					'supersedes_resolution'  => $index( false, 'supersedes_resolution_id' ),
				),
			),
			self::settlements_table()             => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'                   => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'booking_id'           => $required( 'bigint unsigned', false ),
					'event_id'             => $required( 'bigint unsigned', false ),
					'venue_term_id'        => $required( 'bigint unsigned', false ),
					'status'               => $required( 'varchar(16)', false, array( 'default' => 'finalized' ) ),
					'version'              => $required( 'bigint unsigned', false, array( 'default' => '1' ) ),
					'booking_version'      => $required( 'bigint unsigned', false ),
					'basis'                => $required( 'varchar(32)', false ),
					'basis_points'         => $required( 'bigint unsigned', false ),
					'currency'             => $required( 'char(3)', false ),
					'formula_version'      => $required( 'bigint unsigned', false ),
					'included_report_ids'  => $required( 'longtext', false ),
					'evidence_hash'        => $required( 'char(64)', false ),
					'integrity_hash'       => $required( 'char(64)', false ),
					'basis_amount_minor'   => $required( 'bigint', false ),
					'adjustment_minor'     => $required( 'bigint', false ),
					'amount_due_minor'     => $required( 'bigint', false ),
					'finalized_by_user_id' => $required( 'bigint unsigned', false ),
					'finalized_at'         => $required( 'datetime', false ),
					'paid_by_user_id'      => $required( 'bigint unsigned', true ),
					'paid_at'              => $required( 'datetime', true ),
					'payment_reference'    => $required( 'varchar(191)', true ),
					'voided_by_user_id'    => $required( 'bigint unsigned', true ),
					'voided_at'            => $required( 'datetime', true ),
					'void_reason'          => $required( 'varchar(1000)', true ),
					'created_at'           => $required( 'datetime', false ),
					'updated_at'           => $required( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'                => array(
						'unique'  => true,
						'columns' => array( 'id' ),
					),
					'booking_id'             => array(
						'unique'  => true,
						'columns' => array( 'booking_id' ),
					),
					'venue_status_finalized' => array(
						'unique'  => false,
						'columns' => array( 'venue_term_id', 'status', 'finalized_at' ),
					),
					'event_id'               => array(
						'unique'  => false,
						'columns' => array( 'event_id' ),
					),
					'status_updated'         => array(
						'unique'  => false,
						'columns' => array( 'status', 'updated_at' ),
					),
				),
			),
			self::show_settlements_table()        => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'                        => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'public_id'                 => $required( 'char(36)', false ),
					'booking_id'                => $required( 'bigint unsigned', false ),
					'event_id'                  => $required( 'bigint unsigned', false ),
					'venue_term_id'             => $required( 'bigint unsigned', false ),
					'revision'                  => $required( 'bigint unsigned', false ),
					'corrects_revision_id'      => $required( 'bigint unsigned', true ),
					'commission_settlement_id'  => $required( 'bigint unsigned', false ),
					'commission_integrity_hash' => $required( 'char(64)', false ),
					'currency'                  => $required( 'char(3)', false ),
					'formula_version'           => $required( 'bigint unsigned', false ),
					'terms_payload'             => $required( 'longtext', false ),
					'evidence_payload'          => $required( 'longtext', false ),
					'calculation_payload'       => $required( 'longtext', false ),
					'request_hash'              => $required( 'char(64)', false ),
					'integrity_hash'            => $required( 'char(64)', false ),
					'idempotency_key'           => $required( 'varchar(191)', false ),
					'created_by_user_id'        => $required( 'bigint unsigned', false ),
					'created_at'                => $required( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'               => $index( true, 'id' ),
					'public_id'             => $index( true, 'public_id' ),
					'booking_revision'      => $index( true, 'booking_id', 'revision' ),
					'booking_idempotency'   => $index( true, 'booking_id', 'idempotency_key' ),
					'venue_created'         => $index( false, 'venue_term_id', 'created_at', 'id' ),
					'event_id'              => $index( false, 'event_id' ),
					'corrects_revision'     => $index( false, 'corrects_revision_id' ),
					'commission_settlement' => $index( false, 'commission_settlement_id' ),
				),
			),
			self::show_settlement_actions_table() => array(
				'engine'  => 'innodb',
				'columns' => array(
					'id'                 => $required( 'bigint unsigned', false, array( 'extra' => 'auto_increment' ) ),
					'public_id'          => $required( 'char(36)', false ),
					'booking_id'         => $required( 'bigint unsigned', false ),
					'venue_term_id'      => $required( 'bigint unsigned', false ),
					'show_settlement_id' => $required( 'bigint unsigned', false ),
					'action'             => $required( 'varchar(24)', false ),
					'expected_version'   => $required( 'bigint unsigned', false ),
					'payload'            => $required( 'longtext', false ),
					'request_hash'       => $required( 'char(64)', false ),
					'integrity_hash'     => $required( 'char(64)', false ),
					'idempotency_key'    => $required( 'varchar(191)', false ),
					'actor_user_id'      => $required( 'bigint unsigned', false ),
					'created_at'         => $required( 'datetime', false ),
				),
				'indexes' => array(
					'PRIMARY'              => $index( true, 'id' ),
					'public_id'            => $index( true, 'public_id' ),
					'revision_version'     => $index( true, 'show_settlement_id', 'expected_version' ),
					'booking_idempotency'  => $index( true, 'booking_id', 'idempotency_key' ),
					'booking_created'      => $index( false, 'booking_id', 'created_at', 'id' ),
					'venue_action_created' => $index( false, 'venue_term_id', 'action', 'created_at' ),
					'settlement_created'   => $index( false, 'show_settlement_id', 'created_at', 'id' ),
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
			'booking_schema_db_error',
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
