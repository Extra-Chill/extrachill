<?php
/**
 * Private event vendor request storage.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the site-scoped vendor request schema. */
class VendorRequestSchema {

	public const SCHEMA_VERSION = '1';
	public const VERSION_OPTION = 'extrachill_events_vendor_request_schema_version';

	public static function requests_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_vendor_requests';
	}

	public static function applications_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_vendor_applications';
	}

	public static function activity_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_vendor_request_activity';
	}

	/** Install transactional private storage. */
	public static function install() {
		global $wpdb;
		$requests     = self::requests_table();
		$applications = self::applications_table();
		$activity     = self::activity_table();
		$charset      = $wpdb->get_charset_collate();

		$requests_sql = "CREATE TABLE {$requests} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			event_id BIGINT UNSIGNED NOT NULL,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			coordinator_user_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'open',
			policy LONGTEXT NOT NULL,
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY event_id (event_id),
			KEY coordinator_status (coordinator_user_id, status),
			KEY venue_status (venue_term_id, status)
		) ENGINE=InnoDB {$charset};";

		$applications_sql = "CREATE TABLE {$applications} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			request_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'submitted',
			version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			business_name VARCHAR(255) NOT NULL,
			category VARCHAR(191) NOT NULL,
			website_url TEXT NULL,
			footprint VARCHAR(255) NOT NULL,
			power_needs TEXT NULL,
			insurance_notes TEXT NULL,
			message TEXT NOT NULL,
			contact_payload LONGTEXT NOT NULL,
			consent_version BIGINT UNSIGNED NOT NULL DEFAULT '1',
			consented_at DATETIME NOT NULL,
			revoked_at DATETIME NULL,
			private_notes LONGTEXT NULL,
			access_token_hash CHAR(64) NOT NULL,
			idempotency_key VARCHAR(191) NOT NULL,
			request_hash CHAR(64) NOT NULL,
			submitter_user_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY access_token_hash (access_token_hash),
			UNIQUE KEY request_idempotency (request_id, idempotency_key),
			KEY request_status_created (request_id, status, created_at)
		) ENGINE=InnoDB {$charset};";

		$activity_sql = "CREATE TABLE {$activity} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			request_id BIGINT UNSIGNED NOT NULL,
			application_id BIGINT UNSIGNED NULL,
			kind VARCHAR(64) NOT NULL,
			actor_user_id BIGINT UNSIGNED NULL,
			idempotency_key VARCHAR(191) NOT NULL,
			request_hash CHAR(64) NOT NULL,
			result_version BIGINT UNSIGNED NOT NULL,
			payload LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY request_idempotency (request_id, idempotency_key),
			KEY application_created (application_id, created_at, id),
			KEY kind_created (kind, created_at)
		) ENGINE=InnoDB {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $requests_sql );
		$requests_error = (string) $wpdb->last_error;
		dbDelta( $applications_sql );
		$applications_error = (string) $wpdb->last_error;
		dbDelta( $activity_sql );
		$activity_error = (string) $wpdb->last_error;
		if ( '' !== $requests_error || '' !== $applications_error || '' !== $activity_error ) {
			return new \WP_Error( 'vendor_request_schema_install_failed', __( 'Vendor request storage could not be installed.', 'extrachill-events' ) );
		}
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
		return true;
	}

	public static function maybe_install() {
		return self::is_ready() ? true : self::install();
	}

	public static function is_ready(): bool {
		return self::SCHEMA_VERSION === (string) get_option( self::VERSION_OPTION, '' );
	}
}
